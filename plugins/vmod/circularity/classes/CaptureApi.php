<?php namespace Vmod\Circularity\Classes;

use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Vmod\Circularity\Models\Area;
use Vmod\Circularity\Models\Entry;

class CaptureApi
{
    public function options()
    {
        $timezone = config('vmod.circularity::api.reporting_timezone');
        return response()->json(['types' => EntryService::TYPES, 'modes' => EntryService::MODES,
            'destinations' => EntryService::DESTINATIONS, 'reporting_timezone' => $timezone,
            'today' => now($timezone)->format('Y-m-d')]);
    }

    public function areas(Request $request)
    {
        $filters = Validator::make($request->all(), ['branch_ref' => 'nullable|string|max:128'])->validate();
        if (!empty($filters['branch_ref'])) {
            ApiAccess::requireBranch($request, $filters['branch_ref']);
        }
        $query = Area::forContext($request->attributes->get('circularity.context'));
        if (!empty($filters['branch_ref'])) {
            $query->where('branch_ref', $filters['branch_ref']);
        }
        return response()->json(['areas' => $query->orderBy('name')->get()->map(fn ($area) => $this->areaData($area))]);
    }

    public function saveArea(Request $request, ?string $id = null)
    {
        $context = $request->attributes->get('circularity.context');
        $input = $request->all();
        $data = Validator::make($input, [
            'name' => ($id ? 'sometimes|' : 'required|') . 'string|max:120',
            'kind' => [$id ? 'sometimes' : 'required', Rule::in(['stall', 'shared_area'])],
            'is_active' => 'sometimes|boolean',
            'version' => ($id ? 'required|' : 'sometimes|') . 'integer|min:1',
            'branch_ref' => ($id ? 'sometimes|' : 'required|') . 'string|max:128',
        ])->validate();
        if (isset($data['name'])) {
            $data['name'] = trim($data['name']);
            if ($data['name'] === '') {
                throw ValidationException::withMessages(['name' => 'Enter an area name.']);
            }
        }
        try {
            $area = DB::transaction(function () use ($context, $data, $id, $request) {
                if ($id) {
                    $area = Area::forContext($context)->whereKey($id)->lockForUpdate()->firstOrFail();
                    if (isset($data['branch_ref']) && $data['branch_ref'] !== $area->branch_ref) {
                        throw ValidationException::withMessages(['branch_ref' => 'The food court cannot change.']);
                    }
                    if ($area->version !== (int) $data['version']) {
                        EntryService::conflict('area_changed');
                    }
                    $area->version++;
                } else {
                    ApiAccess::requireBranch($request, $data['branch_ref']);
                    $area = new Area;
                    $area->source_namespace = $context['source_namespace'];
                    $area->tenant_ref = $context['tenant_ref'];
                    $area->branch_ref = $data['branch_ref'];
                    $area->version = 1;
                    $area->is_active = true;
                }
                $area->fill(array_intersect_key($data, array_flip(['name', 'kind', 'is_active'])));
                $area->save();
                return $area;
            });
        } catch (QueryException $exception) {
            if (($exception->errorInfo[1] ?? null) !== 1062 || !str_contains($exception->getMessage(), 'circularity_area_name')) {
                throw $exception;
            }
            throw ValidationException::withMessages(['name' => 'This court already has an area with this name, including inactive areas.']);
        }
        return response()->json(['area' => $this->areaData($area)], $id ? 200 : 201);
    }

    public function entries(Request $request)
    {
        $filters = Validator::make($request->all(), [
            'branch_ref' => 'nullable|string|max:128', 'area_id' => 'nullable|integer|min:1',
            'measurement_date' => 'nullable|date_format:Y-m-d',
            'measurement_type' => ['nullable', Rule::in(array_keys(EntryService::TYPES))],
            'destination' => ['nullable', Rule::in(array_keys(EntryService::DESTINATIONS))],
            'page' => 'nullable|integer|min:1|max:1000000',
        ])->validate();
        $context = $request->attributes->get('circularity.context');
        if (!empty($filters['branch_ref'])) {
            ApiAccess::requireBranch($request, $filters['branch_ref']);
        }
        if (!empty($filters['area_id'])) {
            Area::forContext($context)->whereKey($filters['area_id'])->firstOrFail();
        }
        $query = Entry::forContext($context);
        foreach (['branch_ref', 'area_id', 'measurement_date', 'measurement_type', 'destination'] as $field) {
            if (isset($filters[$field]) && $filters[$field] !== '') {
                $query->where($field, $filters[$field]);
            }
        }
        $page = $query->orderByDesc('measurement_date')->orderByDesc('id')->paginate(25);
        return response()->json(['data' => $page->getCollection()->map(fn ($entry) => $this->entryData($entry)),
            'current_page' => $page->currentPage(), 'last_page' => $page->lastPage(),
            'per_page' => $page->perPage(), 'total' => $page->total()]);
    }

    public function entry(Request $request, string $uuid)
    {
        $entry = Entry::withTrashed()->forContext($request->attributes->get('circularity.context'))
            ->where('uuid', strtolower($uuid))->firstOrFail();
        return response()->json(['entry' => $this->entryData($entry)]);
    }

    public function saveEntry(Request $request, ?string $uuid = null)
    {
        $entry = (new EntryService)->save($request->attributes->get('circularity.context'), $request->all(), $uuid);
        return response()->json(['entry' => $this->entryData($entry)], $entry->wasRecentlyCreated ? 201 : 200);
    }

    public function removeEntry(Request $request, string $uuid)
    {
        $entry = (new EntryService)->remove($request->attributes->get('circularity.context'), $uuid, $request->all());
        return response()->json(['entry' => $this->entryData($entry)]);
    }

    private function areaData(Area $area): array
    {
        return $area->only(['id', 'branch_ref', 'name', 'kind', 'is_active', 'version', 'created_at', 'updated_at']);
    }

    private function entryData(Entry $entry): array
    {
        if ($entry->deleted_at) {
            return ['uuid' => $entry->uuid, 'status' => 'removed', 'version' => $entry->version];
        }
        $data = $entry->only(['uuid', 'branch_ref', 'area_id', 'measurement_date', 'measurement_type', 'mode',
            'quantity', 'destination', 'reference', 'notes', 'created_actor_ref', 'updated_actor_ref',
            'version', 'created_at', 'updated_at']);
        $data['unit'] = EntryService::TYPES[$entry->measurement_type]['unit'];
        $data['quantity'] = $data['unit'] === 'count' ? explode('.', $entry->quantity)[0] : $entry->quantity;
        $data['status'] = 'active';
        return $data;
    }
}
