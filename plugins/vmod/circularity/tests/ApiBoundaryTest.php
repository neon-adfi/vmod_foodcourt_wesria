<?php namespace Vmod\Circularity\Tests;

use Illuminate\Http\Request;
use Vmod\Tests\ApplicationTestCase;
use Vmod\Circularity\Classes\ApiAccess;

class ApiBoundaryTest extends ApplicationTestCase
{
    protected $autoMigrate = false;
    private const TOKEN = 'test-only-circularity-credential';

    public function setUp(): void
    {
        parent::setUp();
        config(['vmod.circularity::api.token_hash' => hash('sha256', self::TOKEN),
            'vmod.circularity::api.source_namespace' => 'test-wesria']);
    }

    private function send(string $path, ?string $token = self::TOKEN, ?array $context = null)
    {
        $request = Request::create($path, 'GET');
        $request->headers->set('Accept', 'application/json');
        if ($token !== null) {
            $request->headers->set('Authorization', 'Bearer ' . $token);
        }
        if ($context !== null) {
            $request->headers->set('X-Circularity-Context', json_encode($context));
        }
        $this->app->instance('request', $request);
        return $this->app['router']->dispatch($request);
    }

    private function context(): array
    {
        return ['tenant_ref' => 'company-a', 'branch_refs' => ['court-a'],
            'actor_ref' => 'frontend-user-a', 'permissions' => ['circularity.read']];
    }

    public function testHealthRequiresServiceCredentialAndCreatesNoSession(): void
    {
        $this->assertTrue(class_exists(ApiAccess::class), 'Circularity API boundary must exist.');
        foreach ([null, '', 'wrong'] as $token) {
            $response = $this->send('/api/circularity/v1/health', $token);
            $this->assertSame(401, $response->getStatusCode());
            $this->assertStringNotContainsString(self::TOKEN, $response->getContent());
        }
        $response = $this->send('/api/circularity/v1/health');
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(
            ['service' => 'circularity', 'api_version' => 'v1', 'status' => 'ok'],
            json_decode($response->getContent(), true)
        );
        $this->assertFalse($response->headers->has('Set-Cookie'));
        config(['vmod.circularity::api.token_hash' => '']);
        $this->assertSame(503, $this->send('/api/circularity/v1/health')->getStatusCode());
    }

    public function testScopedMiddlewareRejectsInvalidAuthorityAndRestrictsBranches(): void
    {
        $this->assertTrue(class_exists(ApiAccess::class), 'Scoped API middleware must exist.');
        $this->app['router']->get('/_test/circularity/read', function (Request $request) {
            ApiAccess::requireBranch($request, 'court-a');
            return response()->json($request->attributes->get('circularity.context'));
        })->middleware(ApiAccess::class . ':read');
        $this->app['router']->get('/_test/circularity/write', fn () => response()->json(['ok' => true]))
            ->middleware(ApiAccess::class . ':write');

        $this->assertSame(422, $this->send('/_test/circularity/read')->getStatusCode());
        foreach (['tenant_ref', 'actor_ref'] as $key) {
            $bad = $this->context();
            $bad[$key] = 42;
            $this->assertSame(422, $this->send('/_test/circularity/read', self::TOKEN, $bad)->getStatusCode());
        }
        foreach ([[], ['foreign.permission']] as $permissions) {
            $bad = $this->context();
            $bad['permissions'] = $permissions;
            $this->assertContains($this->send('/_test/circularity/read', self::TOKEN, $bad)->getStatusCode(), [403, 422]);
        }
        $bad = $this->context();
        $bad['branch_refs'] = [];
        $this->assertSame(403, $this->send('/_test/circularity/read', self::TOKEN, $bad)->getStatusCode());
        $this->assertSame(403, $this->send('/_test/circularity/write', self::TOKEN, $this->context())->getStatusCode());
        $context = $this->context();
        $context['source_namespace'] = 'browser-forged';
        $response = $this->send('/_test/circularity/read', self::TOKEN, $context);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('test-wesria', json_decode($response->getContent(), true)['source_namespace']);
        $context['permissions'][] = 'circularity.write';
        $this->assertSame(200, $this->send('/_test/circularity/write', self::TOKEN, $context)->getStatusCode());
        $context['branch_refs'] = ['court-b'];
        $this->assertSame(404, $this->send('/_test/circularity/read', self::TOKEN, $context)->getStatusCode());
    }

    public function testValidationIsJsonBeforeTheHostCmsErrorRendererCanReplaceIt(): void
    {
        // Normal HTTP boot lets October's CMS renderer replace validation with an HTML 500.
        \Event::listen('exception.beforeRender', fn () => 'Host CMS error page');
        $this->app['router']->get('/api/circularity/v1/_test-invalid', function () {
            \Illuminate\Support\Facades\Validator::make(['quantity' => 'bad'], ['quantity' => 'integer'])->validate();
        })->middleware(ApiAccess::class . ':read');
        $response = $this->send('/api/circularity/v1/_test-invalid', self::TOKEN, $this->context());
        $this->assertSame(422, $response->getStatusCode());
        $this->assertArrayHasKey('quantity', json_decode($response->getContent(), true)['errors']);
    }
}
