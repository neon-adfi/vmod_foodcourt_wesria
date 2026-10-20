<?php namespace Vmod\CircularityUi\Classes;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
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
        return $result;
    }
}
