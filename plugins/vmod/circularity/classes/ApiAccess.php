<?php namespace Vmod\Circularity\Classes;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use JsonException;

class ApiAccess
{
    public function handle(Request $request, Closure $next, string $permission = '')
    {
        $hash = config('vmod.circularity::api.token_hash');
        $namespace = config('vmod.circularity::api.source_namespace');
        if (!is_string($hash) || !preg_match('/\A[a-f0-9]{64}\z/', $hash)
            || !is_string($namespace) || trim($namespace) === '') {
            return response()->json(['message' => 'Circularity service is unavailable.'], 503);
        }
        $token = $request->bearerToken();
        if (!is_string($token) || $token === '' || !hash_equals($hash, hash('sha256', $token))) {
            return response()->json(['message' => 'Service authentication required.'], 401);
        }
        if ($permission === '') {
            return $next($request);
        }
        if (!in_array($permission, ['read', 'write'], true)) {
            throw new \LogicException('Unsupported circularity permission.');
        }
        try {
            $context = json_decode($request->header('X-Circularity-Context', ''), true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            $context = null;
        }
        if (!is_array($context) || !is_array($context['branch_refs'] ?? null)
            || !array_is_list($context['branch_refs']) || !is_array($context['permissions'] ?? null)
            || !array_is_list($context['permissions'])) {
            return response()->json(['message' => 'Invalid circularity context.'], 422);
        }
        $validator = Validator::make($context, [
            'tenant_ref' => 'required|string|max:128',
            'actor_ref' => 'required|string|max:128',
            'branch_refs' => 'present|array',
            'branch_refs.*' => 'required|string|max:128|distinct',
            'permissions' => 'present|array',
            'permissions.*' => ['string', 'distinct', Rule::in(['circularity.read', 'circularity.write'])],
        ]);
        if ($validator->fails()) {
            return response()->json(['message' => 'Invalid circularity context.'], 422);
        }
        $context = $validator->validated();
        if (!$context['branch_refs'] || !in_array('circularity.read', $context['permissions'], true)
            || ($permission === 'write' && !in_array('circularity.write', $context['permissions'], true))) {
            return response()->json(['message' => 'Circularity access denied.'], 403);
        }
        $context['source_namespace'] = $namespace;
        $request->attributes->set('circularity.context', $context);
        return $next($request);
    }

    public static function requireBranch(Request $request, string $branchRef): void
    {
        $context = $request->attributes->get('circularity.context', []);
        abort_unless(in_array($branchRef, $context['branch_refs'] ?? [], true), 404);
    }
}
