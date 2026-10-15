<?php namespace Vmod\Erp\Classes;

use DB;
use Validator;
use October\Rain\Exception\ValidationException;
use Vmod\Core\Classes\Workspace;
use Vmod\Core\Models\Company;
use Vmod\Core\Models\Audit;

// Shared only by the three document/stock services; this is not a workflow or generic CRUD layer.
class DocumentOperation
{
    public static function run(string $action, string $permission, array $input, callable $scope, callable $write, array $extraPermissions = [])
    {
        Workspace::assertContext();
        $companyId = Workspace::authorize($permission)->company_id;
        self::validate($input, ['operation_key' => 'required|uuid', 'id' => 'nullable|integer|min:1', 'version' => 'required_with:id|integer|min:1']);
        $fingerprint = hash('sha256', json_encode(self::normalize($input), JSON_THROW_ON_ERROR));
        return DB::transaction(function () use ($action, $permission, $input, $scope, $write, $companyId, $fingerprint, $extraPermissions) {
            $company = Company::whereKey($companyId)->sharedLock()->firstOrFail();
            Workspace::assertContext();
            $actor = Workspace::authorize($permission);
            foreach ($extraPermissions as $extra) {
                Workspace::authorize($extra);
            }
            abort_unless((int) $actor->company_id === (int) $companyId, 409);
            $key = ['company_id' => $companyId, 'action' => $action, 'operation_key' => $input['operation_key']];
            DB::table('vmod_erp_operations')->insertOrIgnore($key + ['fingerprint' => $fingerprint, 'created_at' => now()]);
            $operation = DB::table('vmod_erp_operations')->where($key)->lockForUpdate()->first();
            // Scope before replay, and replay before stale version/state checks. Never resolve current prices into this hash.
            $record = $scope($actor, $operation->result_id);
            abort_unless(hash_equals($operation->fingerprint, $fingerprint), 409, 'This operation key was already used with different input.');
            if ($operation->result_id) {
                return $record;
            }
            if (!empty($input['id'])) {
                abort_unless((int) $record->version === (int) $input['version'], 409, 'This document changed. Reload before saving.');
            }
            $result = $write($actor, $company, $record, $operation->id);
            $details = ['id' => $result->id, 'operation_id' => $operation->id];
            if (isset($input['reason'])) {
                $details['reason'] = trim($input['reason']);
            }
            Audit::record($companyId, $action, $details, $actor->user_id);
            DB::table('vmod_erp_operations')->where('id', $operation->id)->update(['result_id' => $result->id]);
            return $result;
        }, 3);
    }

    public static function validate(array $input, array $rules): array
    {
        $validator = Validator::make($input, $rules);
        if ($validator->fails()) {
            throw new ValidationException($validator);
        }
        return $validator->validated();
    }

    public static function branch($actor, $branchId): int
    {
        self::validate(['branch_id' => $branchId], ['branch_id' => 'required|integer|min:1']);
        $branchId = (int) $branchId;
        abort_unless(in_array($branchId, $actor->branchIds(), true) && (!Workspace::branchId() || Workspace::branchId() === $branchId), 404);
        return $branchId;
    }

    public static function scoped(string $class, $actor)
    {
        return $class::where('company_id', $actor->company_id)->whereIn('branch_id', Workspace::branchId() ? [Workspace::branchId()] : $actor->branchIds());
    }

    public static function number(int $companyId, string $type): string
    {
        $key = ['company_id' => $companyId, 'type' => $type];
        DB::table('vmod_erp_document_counters')->insertOrIgnore($key + ['last_number' => 0]);
        $counter = DB::table('vmod_erp_document_counters')->where($key)->lockForUpdate()->first();
        $number = $counter->last_number + 1;
        DB::table('vmod_erp_document_counters')->where($key)->update(['last_number' => $number]);
        return $type . '-' . str_pad((string) $number, 6, '0', STR_PAD_LEFT);
    }

    public static function reason(array $input): string
    {
        $reason = self::validate($input, ['reason' => 'required|string|max:2000'])['reason'];
        if (trim($reason) === '') {
            throw new ValidationException(['reason' => 'Enter a reason.']);
        }
        return trim($reason);
    }

    private static function normalize(array $input): array
    {
        unset($input['operation_key'], $input['version']);
        foreach ($input as $key => &$value) {
            if (is_array($value)) {
                $value = self::normalize($value);
            } elseif (is_string($value)) {
                $value = trim($value);
                if (in_array($key, ['quantity', 'unit_price'], true) && preg_match('/^\d{1,15}(?:\.\d{1,4})?$/D', $value)) {
                    $value = DocumentTotals::decimal($value, (string) $key);
                } elseif (($key === 'id' || str_ends_with((string) $key, '_id')) && ctype_digit($value)) {
                    $value = (int) $value;
                }
            }
        }
        unset($value);
        if (!array_is_list($input)) {
            ksort($input);
        }
        return $input;
    }
}
