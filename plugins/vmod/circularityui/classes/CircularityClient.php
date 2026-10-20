<?php namespace Vmod\CircularityUi\Classes;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use JsonException;
use October\Rain\Exception\ValidationException;
use Vmod\Core\Classes\Workspace;

class CircularityClient
{
    public function health(): array
    {
        $result = $this->send('GET', '/health', [], null);
        abort_unless(
            ($result['service'] ?? null) === 'circularity'
            && ($result['api_version'] ?? null) === 'v1' && ($result['status'] ?? null) === 'ok',
            503,
            'Circularity service is unavailable.'
        );
        return $result;
    }

    public function request(string $method, string $path, array $data = [], ?string $branchRef = null): array
    {
        $method = strtoupper($method);
        if (!in_array($method, ['GET', 'POST', 'PATCH', 'DELETE'], true)
            || !preg_match('#\A/(?:[a-z0-9_-]+/?)+\z#', $path)) {
            throw new \InvalidArgumentException('Invalid circularity API request.');
        }
        $write = $method !== 'GET';
        if ($write || \Request::ajax()) {
            Workspace::assertContext();
        }
        $member = Workspace::authorize('circularity.read');
        $permissions = array_values(array_intersect($member->role->permissions ?? [], ['circularity.read', 'circularity.write']));
        abort_if($write && !in_array('circularity.write', $permissions, true), 403, 'Circularity access denied.');
        $branches = array_map('strval', $member->branchIds());
        $selected = Workspace::branchId();
        if ($selected !== null) {
            $branches = array_values(array_intersect($branches, [(string) $selected]));
        }
        abort_unless($branches, 403, 'Circularity access denied.');
        $branchRef = $branchRef ?? ($selected !== null ? (string) $selected : null);
        if ($branchRef !== null) {
            abort_unless(in_array($branchRef, $branches, true), 404);
            $branches = [$branchRef];
        }
        if ($write) {
            abort_unless($branchRef !== null, 422, 'Choose a food court before saving.');
            $data['branch_ref'] = $branchRef;
        }
        // No Core lock spans HTTP. Revocation applies to later dispatches, not an in-flight request.
        return $this->send($method, $path, $data, [
            'tenant_ref' => (string) $member->company_id, 'branch_refs' => $branches,
            'actor_ref' => (string) $member->user_id, 'permissions' => $permissions,
        ]);
    }

    private function send(string $method, string $path, array $data, ?array $context): array
    {
        $url = config('vmod.circularityui::api.url');
        $token = config('vmod.circularityui::api.token');
        $parts = is_string($url) ? parse_url($url) : false;
        $internal = config('vmod.circularityui::api.allow_internal_http')
            && app()->environment(['local', 'testing']) && ($parts['scheme'] ?? '') === 'http'
            && ($parts['host'] ?? '') === 'nginx-tech';
        abort_unless(
            is_array($parts) && !empty($parts['host'])
            && (($parts['scheme'] ?? '') === 'https' || $internal)
            && !isset($parts['user']) && !isset($parts['pass']) && !isset($parts['query']) && !isset($parts['fragment'])
            && is_string($token) && $token !== '' && !preg_match('/[\r\n]/', $token),
            503,
            'Circularity service is unavailable.'
        );
        $client = Http::baseUrl(rtrim($url, '/'))->acceptJson()->withToken($token)
            ->connectTimeout(2)->timeout(10)->withoutRedirecting()->withOptions(['cookies' => false]);
        if ($context !== null) {
            $client->withHeaders(['X-Circularity-Context' => json_encode($context, JSON_THROW_ON_ERROR)]);
        }
        try {
            $response = $client->send($method, $path, [$method === 'GET' ? 'query' : 'json' => $data]);
        } catch (ConnectionException $exception) {
            abort(503, 'Circularity service is unavailable.');
        }
        if (!$response->successful()) {
            if ($context !== null && $response->status() === 409) {
                $code = $response->json('code');
                $allowed = in_array($code, ['entry_changed', 'area_changed', 'uuid_conflict', 'mode_locked', 'daily_total_exists'], true);
                $data = ['message' => 'Circularity data changed. Check the saved record before continuing.',
                    'code' => $allowed ? $code : 'conflict'];
                $uuid = $response->json('uuid');
                if ($allowed && is_string($uuid) && preg_match('/\A[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}\z/', $uuid)) {
                    $data['uuid'] = $uuid;
                }
                throw new HttpResponseException(response()->json($data, 409));
            }
            $messages = [403 => 'Circularity access denied.', 404 => 'Circularity record not found.',
                409 => 'Circularity data changed. Reload before continuing.', 429 => 'Circularity service is busy. Try again later.'];
            if ($context !== null && isset($messages[$response->status()])) {
                abort($response->status(), $messages[$response->status()]);
            }
            if ($context !== null && $response->status() === 422) {
                $errors = $response->json('errors');
                if (is_array($errors) && $errors && count($errors) <= 50) {
                    foreach ($errors as $field => $messages) {
                        if (!is_string($field) || !preg_match('/\A[a-zA-Z0-9_.-]{1,128}\z/', $field)
                            || !is_array($messages) || !$messages || count($messages) > 10
                            || array_filter($messages, fn ($message) => !is_string($message) || strlen($message) > 1000)) {
                            abort(503, 'Circularity service is unavailable.');
                        }
                        // Native October validation inserts messages as HTML.
                        $errors[$field] = array_map(fn ($message) => htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), $messages);
                    }
                    throw new ValidationException($errors);
                }
            }
            abort(503, 'Circularity service is unavailable.');
        }
        try {
            $result = json_decode($response->body(), true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            abort(503, 'Circularity service is unavailable.');
        }
        abort_unless(is_array($result) && !array_is_list($result), 503, 'Circularity service is unavailable.');
        $this->validateResponse($method, $path, $result);
        return $result;
    }

    private function validateResponse(string $method, string $path, array $result): void
    {
        $valid = true;
        if ($path === '/options') {
            $valid = Validator::make($result, ['types' => 'required|array', 'modes' => 'required|array',
                'destinations' => 'required|array', 'reporting_timezone' => 'required|timezone',
                'today' => 'required|date_format:Y-m-d'])->passes();
            foreach (is_array($result['types'] ?? null) ? $result['types'] : [] as $key => $type) {
                $valid = $valid && is_string($key) && is_array($type)
                    && is_string($type['label'] ?? null) && in_array($type['unit'] ?? '', ['kg', 'count'], true);
            }
            foreach (['modes', 'destinations'] as $field) {
                foreach (is_array($result[$field] ?? null) ? $result[$field] : [] as $key => $label) {
                    $valid = $valid && is_string($key) && is_string($label);
                }
            }
        } elseif ($path === '/areas' || preg_match('#\A/areas/[1-9][0-9]*\z#', $path)) {
            $list = $method === 'GET' && $path === '/areas';
            $rows = $list ? ($result['areas'] ?? null) : (isset($result['area']) ? [$result['area']] : null);
            $valid = is_array($rows) && array_is_list($rows);
            foreach ($valid ? $rows : [] as $row) {
                $valid = $valid && is_array($row) && Validator::make($row, [
                    'id' => 'required|integer|min:1', 'branch_ref' => 'required|string|max:128',
                    'name' => 'required|string|max:120', 'kind' => 'required|in:stall,shared_area',
                    'version' => 'required|integer|min:1', 'is_active' => 'required|boolean',
                ])->passes() && is_bool($row['is_active'] ?? null) && is_int($row['id'] ?? null) && is_int($row['version'] ?? null);
            }
        } elseif ($path === '/entries' || str_starts_with($path, '/entries/')) {
            $list = $method === 'GET' && $path === '/entries';
            $rows = $list ? ($result['data'] ?? null) : (isset($result['entry']) ? [$result['entry']] : null);
            $valid = is_array($rows) && array_is_list($rows);
            if ($list) {
                $valid = $valid && Validator::make($result, [
                    'current_page' => 'required|integer|min:1', 'last_page' => 'required|integer|min:1',
                    'per_page' => 'required|in:25', 'total' => 'required|integer|min:0',
                ])->passes();
            }
            foreach ($valid ? $rows : [] as $row) {
                $valid = $valid && $this->validEntry($row);
                if ($valid && !$list && $path !== '/entries') {
                    $valid = strtolower($row['uuid']) === basename($path);
                }
                if ($valid && ($method === 'POST' || $method === 'PATCH')) {
                    $valid = $row['status'] === 'active';
                }
            }
        }
        abort_unless($valid, 503, 'Circularity service is unavailable.');
    }

    private function validEntry($row): bool
    {
        if (!is_array($row) || Validator::make($row, ['uuid' => 'required|uuid',
            'status' => 'required|in:active,removed', 'version' => 'required|integer|min:1'])->fails()
            || !is_int($row['version'])) {
            return false;
        }
        if ($row['status'] === 'removed') {
            return true;
        }
        if (Validator::make($row, [
            'branch_ref' => 'required|string|max:128', 'area_id' => 'required|integer|min:1',
            'measurement_date' => 'required|date_format:Y-m-d',
            'measurement_type' => 'required|in:food_waste,packaging_waste,mixed_waste,reusable_issued,reusable_returned',
            'mode' => 'required|in:daily_total,individual', 'unit' => 'required|in:kg,count',
            'destination' => 'required|in:unknown,landfill,incineration,recycling,composting,anaerobic_digestion,animal_feed,other,not_applicable',
            'reference' => 'present|nullable|string|max:120', 'notes' => 'present|nullable|string|max:2000',
            'created_actor_ref' => 'required|string|max:128', 'updated_actor_ref' => 'required|string|max:128',
        ])->fails() || !is_string($row['quantity'] ?? null)) {
            return false;
        }
        $count = str_starts_with($row['measurement_type'], 'reusable_');
        return $row['unit'] === ($count ? 'count' : 'kg')
            && ($row['destination'] === 'not_applicable') === $count
            && preg_match($count ? '/\A[0-9]{1,12}\z/' : '/\A[0-9]{1,12}\.[0-9]{3}\z/', $row['quantity']) === 1;
    }
}
