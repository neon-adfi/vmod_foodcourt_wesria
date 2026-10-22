<?php namespace Vmod\Circularity\Classes;

use Illuminate\Database\QueryException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Vmod\Circularity\Models\Area;
use Vmod\Circularity\Models\Entry;

class EntryService
{
    public const TYPES = [
        'food_waste' => ['label' => 'Food waste', 'unit' => 'kg'],
        'packaging_waste' => ['label' => 'Packaging waste', 'unit' => 'kg'],
        'mixed_waste' => ['label' => 'Mixed waste', 'unit' => 'kg'],
        'used_cooking_oil' => ['label' => 'Used cooking oil', 'unit' => 'kg'],
        'reusable_issued' => ['label' => 'Reusable items issued', 'unit' => 'count'],
        'reusable_returned' => ['label' => 'Reusable items returned', 'unit' => 'count'],
    ];
    public const MODES = ['daily_total' => 'Daily total', 'individual' => 'Individual entries'];
    public const DESTINATIONS = [
        'unknown' => 'Unknown', 'landfill' => 'Landfill', 'incineration' => 'Incineration',
        'recycling' => 'Recycling', 'composting' => 'Composting', 'anaerobic_digestion' => 'Anaerobic digestion',
        'animal_feed' => 'Animal feed', 'other' => 'Other', 'not_applicable' => 'Not applicable',
    ];

    public static function conflict(string $code, ?string $uuid = null): void
    {
        throw new HttpResponseException(response()->json(array_filter([
            'message' => 'Circularity data changed. Check the saved record before continuing.',
            'code' => $code, 'uuid' => $uuid,
        ]), 409));
    }

    public function save(array $context, array $input, ?string $uuid = null): Entry
    {
        $create = $uuid === null;
        $identity = Validator::make($input, $create ? ['uuid' => 'required|uuid', 'branch_ref' => 'required|string|max:128'] : [
            'version' => 'required|integer|min:1', 'uuid' => 'sometimes|uuid',
        ])->validate();
        if ($create) {
            abort_unless(in_array($identity['branch_ref'], $context['branch_refs'], true), 404);
        }
        $uuid = strtolower($uuid ?? $identity['uuid']);
        try {
            return $this->mutate($context, $input, $uuid, $create, false);
        } catch (QueryException $exception) {
            if (!$create || ($exception->errorInfo[1] ?? null) !== 1062
                || !str_contains($exception->getMessage(), 'circularity_entry_uuid')) {
                throw $exception;
            }
            // The losing insert has rolled back; a fresh transaction sees the committed winner.
            return $this->mutate($context, $input, $uuid, true, false, true);
        }
    }

    public function remove(array $context, string $uuid, array $input): Entry
    {
        Validator::make($input, ['version' => 'required|integer|min:1'])->validate();
        return $this->mutate($context, $input, strtolower($uuid), false, true);
    }

    private function mutate(array $context, array $input, string $uuid, bool $create, bool $remove, bool $mustExist = false): Entry
    {
        return DB::transaction(function () use ($context, $input, $uuid, $create, $remove, $mustExist) {
            $advisory = Entry::withTrashed()->forContext($context)->where('uuid', $uuid)->first();
            abort_if((!$create || $mustExist) && !$advisory, 404);
            $branch = $advisory ? $advisory->branch_ref : ($input['branch_ref'] ?? null);
            Validator::make(['branch_ref' => $branch], ['branch_ref' => 'required|string|max:128'])->validate();
            abort_unless(in_array($branch, $context['branch_refs'], true), 404);
            if ($advisory && isset($input['branch_ref']) && $input['branch_ref'] !== $branch) {
                if ($create) {
                    self::conflict('uuid_conflict', $uuid);
                }
                throw ValidationException::withMessages(['branch_ref' => 'The food court cannot change. Remove and recreate this entry.']);
            }
            if (!$create && isset($input['uuid']) && strtolower($input['uuid']) !== $uuid) {
                throw ValidationException::withMessages(['uuid' => 'The entry identity cannot change.']);
            }
            $data = $remove ? [] : $this->normalize(array_replace(!$create && $advisory ? $this->storedFields($advisory) : [], $input));
            $areaIds = array_unique(array_filter([$advisory?->area_id, $data['area_id'] ?? null]));
            sort($areaIds, SORT_NUMERIC);
            $areas = [];
            // Entry writes serialize per area; sufficient for manual capture.
            // Introduce narrower group locks only if measured contention warrants it.
            foreach ($areaIds as $areaId) {
                $areas[$areaId] = Area::forContext($context)->where('branch_ref', $branch)
                    ->whereKey($areaId)->lockForUpdate()->firstOrFail();
            }
            // A new UUID relies on its unique index, avoiding competing locks on a missing index gap.
            $entry = $advisory
                ? Entry::withTrashed()->forContext($context)->where('uuid', $uuid)->lockForUpdate()->first() : null;
            if ($entry && (!$advisory || $entry->area_id !== $advisory->area_id)) {
                self::conflict('entry_changed', $uuid);
            }
            if ($create && $entry) {
                if ($entry->deleted_at || $this->normalize($this->storedFields($entry)) !== $data) {
                    self::conflict('uuid_conflict', $uuid);
                }
                return $entry;
            }
            abort_if(!$create && !$entry, 404);
            if ($entry && ($entry->deleted_at || $entry->version !== (int) $input['version'])) {
                self::conflict('entry_changed', $uuid);
            }
            if ($remove) {
                $entry->updated_actor_ref = $context['actor_ref'];
                $entry->version++;
                $entry->deleted_at = now();
                $entry->save();
                return $entry;
            }
            if (!$areas[$data['area_id']]->is_active && (!$entry || $entry->area_id !== $data['area_id'])) {
                throw ValidationException::withMessages(['area_id' => 'Choose an active area for a new entry or move.']);
            }
            if ($entry && $entry->mode !== $data['mode']) {
                self::conflict('mode_locked', $uuid);
            }
            $group = Entry::forContext($context)->where('branch_ref', $branch)->where('area_id', $data['area_id'])
                ->where('measurement_date', $data['measurement_date'])->where('measurement_type', $data['measurement_type'])
                ->where('uuid', '<>', $uuid)->lockForUpdate()->get();
            foreach ($group as $other) {
                if ($other->mode !== $data['mode']) {
                    self::conflict('mode_locked');
                }
                if ($data['mode'] === 'daily_total' && $other->destination === $data['destination']) {
                    self::conflict('daily_total_exists', $other->uuid);
                }
            }
            if (!$entry) {
                $entry = new Entry;
                $entry->uuid = $uuid;
                $entry->source_namespace = $context['source_namespace'];
                $entry->tenant_ref = $context['tenant_ref'];
                $entry->branch_ref = $branch;
                $entry->created_actor_ref = $context['actor_ref'];
                $entry->version = 1;
            } else {
                $entry->version++;
            }
            $entry->fill($data);
            $entry->updated_actor_ref = $context['actor_ref'];
            $entry->save();
            return $entry;
        });
    }

    private function normalize(array $input): array
    {
        $data = Validator::make($input, [
            'area_id' => 'required|integer|min:1',
            'measurement_date' => 'required|date_format:Y-m-d|before_or_equal:' . now(config('vmod.circularity::api.reporting_timezone'))->format('Y-m-d'),
            'measurement_type' => ['required', Rule::in(array_keys(self::TYPES))],
            'mode' => ['required', Rule::in(array_keys(self::MODES))],
            'destination' => ['required', Rule::in(array_keys(self::DESTINATIONS))],
            'quantity' => ['required', function ($attribute, $value, $fail) use ($input) {
                $type = $input['measurement_type'] ?? '';
                $count = is_string($type) && (self::TYPES[$type]['unit'] ?? '') === 'count';
                $pattern = $count ? '/\A[0-9]+\z/' : '/\A[0-9]+(?:\.[0-9]{1,3})?\z/';
                if (!is_string($value) || strlen($value) > 40 || !preg_match($pattern, $value)
                    || strlen(ltrim(explode('.', $value)[0], '0')) > 12) {
                    $fail($count ? 'Enter a nonnegative whole count, at most 999999999999.'
                        : 'Enter nonnegative kilograms, at most 999999999999.999, with up to three decimals.');
                }
            }],
            'reference' => 'nullable|string|max:120', 'notes' => 'nullable|string|max:2000',
        ])->validate();
        $count = self::TYPES[$data['measurement_type']]['unit'] === 'count';
        if (($data['destination'] === 'not_applicable') !== $count) {
            throw ValidationException::withMessages(['destination' => $count ? 'Reusable counts use Not applicable.' : 'Choose a waste destination.']);
        }
        $parts = explode('.', $data['quantity']);
        $data['quantity'] = (ltrim($parts[0], '0') ?: '0') . '.' . str_pad($parts[1] ?? '', 3, '0');
        $data['area_id'] = (int) $data['area_id'];
        foreach (['reference', 'notes'] as $field) {
            $value = trim($data[$field] ?? '');
            $data[$field] = $value === '' ? null : $value;
        }
        return $data;
    }

    private function storedFields(Entry $entry): array
    {
        $data = $entry->getAttributes();
        if (self::TYPES[$entry->measurement_type]['unit'] === 'count') {
            $data['quantity'] = explode('.', $entry->quantity)[0];
        }
        return $data;
    }
}
