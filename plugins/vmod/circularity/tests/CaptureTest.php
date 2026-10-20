<?php namespace Vmod\Circularity\Tests;

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Vmod\Tests\ApplicationTestCase;

class CaptureTest extends ApplicationTestCase
{
    protected $autoMigrate = false;
    private $context;

    public function setUp(): void
    {
        parent::setUp();
        $this->app->make(\Illuminate\Contracts\Console\Kernel::class)->call('october:migrate', ['--no-interaction' => true]);
        $this->app['db']->beginTransaction();
        config(['vmod.circularity::api.token_hash' => hash('sha256', 'capture-test'),
            'vmod.circularity::api.source_namespace' => 'capture-test']);
        $this->context = ['tenant_ref' => 'Tenant-A', 'branch_refs' => ['Court-A', 'Court-B'],
            'actor_ref' => 'User-A', 'permissions' => ['circularity.read', 'circularity.write']];
    }

    public function tearDown(): void
    {
        while ($this->app['db']->transactionLevel()) {
            $this->app['db']->rollBack();
        }
        parent::tearDown();
    }

    private function api(string $method, string $path, array $data = [], int $status = 200): array
    {
        $request = Request::create('/api/circularity/v1' . $path, $method, $data);
        $request->headers->set('Accept', 'application/json');
        $request->headers->set('Authorization', 'Bearer capture-test');
        $request->headers->set('X-Circularity-Context', json_encode($this->context));
        $this->app->instance('request', $request);
        try {
            $response = $this->app['router']->dispatch($request);
        } catch (\Throwable $exception) {
            $response = $this->app->make(ExceptionHandler::class)->render($request, $exception);
        }
        $this->assertSame($status, $response->getStatusCode(), $response->getContent());
        $result = json_decode($response->getContent(), true);
        $this->assertIsArray($result);
        $this->assertFalse(array_is_list($result));
        return $result;
    }

    private function area(string $name = 'Stall A', string $branch = 'Court-A'): array
    {
        return $this->api('POST', '/areas', ['name' => $name, 'kind' => 'stall', 'branch_ref' => $branch], 201)['area'];
    }

    private function measurement(array $area, array $changes = []): array
    {
        return array_replace(['uuid' => (string) Str::uuid(), 'branch_ref' => $area['branch_ref'],
            'area_id' => $area['id'], 'measurement_date' => '2026-01-01', 'measurement_type' => 'food_waste',
            'mode' => 'daily_total', 'quantity' => '1.25', 'destination' => 'composting',
            'reference' => '', 'notes' => ''], $changes);
    }

    public function testAreasRetainIdentityAndUniqueNamesIncludingInactiveRows(): void
    {
        $area = $this->area('  Shared Kitchen  ');
        $this->assertSame('Shared Kitchen', $area['name']);
        $this->api('POST', '/areas', ['name' => 'shared kitchen', 'kind' => 'shared_area', 'branch_ref' => 'Court-A'], 422);
        $off = $this->api('PATCH', '/areas/' . $area['id'], ['version' => 1, 'name' => 'Shared Kitchen',
            'kind' => 'stall', 'is_active' => false])['area'];
        $this->assertSame(2, $off['version']);
        $this->api('POST', '/areas', ['name' => 'SHARED KITCHEN', 'kind' => 'stall', 'branch_ref' => 'Court-A'], 422);
        $this->api('PATCH', '/areas/' . $area['id'], ['version' => 1, 'is_active' => true], 409);
        $on = $this->api('PATCH', '/areas/' . $area['id'], ['version' => 2, 'is_active' => true])['area'];
        $this->assertSame($area['id'], $on['id']);
        $this->assertTrue($on['is_active']);
        $this->area('shared kitchen', 'Court-B');
        $this->api('PATCH', '/areas/' . $area['id'], ['version' => 3, 'branch_ref' => 'Court-B'], 422);
    }

    public function testSummaryReconcilesExactCurrentMeasurementsAndKeepsMissingZeroAndCountsDistinct(): void
    {
        $area = $this->area();
        $create = fn ($changes) => $this->api('POST', '/entries', $this->measurement($area, $changes), 201)['entry'];
        $food = $create(['quantity' => '10.125']);
        $create(['quantity' => '2.000', 'destination' => 'unknown']);
        $create(['measurement_type' => 'mixed_waste', 'quantity' => '3.250', 'destination' => 'unknown']);
        foreach (['reusable_issued' => '8', 'reusable_returned' => '12'] as $type => $quantity) {
            $create(['measurement_type' => $type, 'quantity' => $quantity, 'destination' => 'not_applicable']);
        }
        $range = ['date_from' => '2026-01-01', 'date_to' => '2026-01-01', 'area_id' => $area['id']];
        $totals = $this->api('GET', '/summary', $range)['totals'];
        $this->assertSame('12.125', $totals['food_waste']['quantity']);
        $this->assertSame(2, $totals['food_waste']['entry_count']);
        $destinations = array_column($totals['food_waste']['destinations'], 'quantity', 'destination');
        $this->assertSame('10.125', $destinations['composting']);
        $this->assertSame('2.000', $destinations['unknown']);
        $this->assertSame('3.250', $totals['mixed_waste']['quantity']);
        $this->assertNull($totals['packaging_waste']['quantity']);
        $this->assertSame('8', $totals['reusable_issued']['quantity']);
        $this->assertSame('12', $totals['reusable_returned']['quantity']);
        $create(['measurement_type' => 'packaging_waste', 'quantity' => '0', 'destination' => 'recycling']);
        $this->assertSame('0.000', $this->api('GET', '/summary', $range)['totals']['packaging_waste']['quantity']);
        $filtered = $this->api('GET', '/summary', $range + ['destination' => 'composting'])['totals'];
        $this->assertSame('10.125', $filtered['food_waste']['quantity']);
        $this->assertNull($filtered['reusable_returned']['quantity']);
        $this->assertSame(0, $filtered['reusable_returned']['entry_count']);

        $this->api('PATCH', '/areas/' . $area['id'], ['version' => 1, 'is_active' => false]);
        $this->api('PATCH', '/entries/' . $food['uuid'], ['version' => 1, 'quantity' => '1.125']);
        $this->assertSame('3.125', $this->api('GET', '/summary', $range)['totals']['food_waste']['quantity']);
        $this->api('DELETE', '/entries/' . $food['uuid'], ['version' => 2]);
        $this->assertSame('2.000', $this->api('GET', '/summary', $range)['totals']['food_waste']['quantity']);
    }

    public function testRangesAreInclusiveAndListsKeepHistoryAndExactDateCompatibility(): void
    {
        $area = $this->area();
        foreach (['2025-12-31', '2026-01-01', '2026-01-31', '2026-02-01'] as $date) {
            $this->api('POST', '/entries', $this->measurement($area, ['measurement_date' => $date]), 201);
        }
        $range = ['date_from' => '2026-01-01', 'date_to' => '2026-01-31'];
        $this->assertSame(4, $this->api('GET', '/entries')['total']);
        $this->assertSame(2, $this->api('GET', '/entries', $range)['total']);
        $this->assertSame('2.500', $this->api('GET', '/summary', $range)['totals']['food_waste']['quantity']);
        $this->assertSame(1, $this->api('GET', '/entries', ['measurement_date' => '2025-12-31'])['total']);
        foreach ([
            ['date_from' => '2026-01-01'], ['date_to' => '2026-01-31'],
            ['date_from' => '2026-02-30', 'date_to' => '2026-03-01'],
            ['date_from' => '2026-02-01', 'date_to' => '2026-01-01'],
            ['date_from' => ['2026-01-01'], 'date_to' => '2026-01-01'],
            $range + ['measurement_date' => '2026-01-01'],
        ] as $invalid) {
            $this->api('GET', '/entries', $invalid, 422);
            $this->api('GET', '/summary', $invalid, 422);
        }
        \Carbon\Carbon::setTestNow('2026-02-28 16:30:00 UTC');
        try {
            $summary = $this->api('GET', '/summary');
            $this->assertSame('Asia/Kuala_Lumpur', $summary['reporting_timezone']);
            $this->assertSame('2026-03-01', $summary['filters']['date_from']);
            $this->assertSame('2026-03-01', $summary['filters']['date_to']);
            $this->assertNull($summary['totals']['food_waste']['quantity']);
        } finally {
            \Carbon\Carbon::setTestNow();
        }
    }

    public function testSummaryIsNotPaginatedAndAggregatesCanExceedAnEntryMaximum(): void
    {
        $area = $this->area();
        for ($index = 0; $index < 26; $index++) {
            $this->api('POST', '/entries', $this->measurement($area, ['mode' => 'individual', 'quantity' => '0.001']), 201);
        }
        $range = ['date_from' => '2026-01-01', 'date_to' => '2026-01-01'];
        $this->assertCount(25, $this->api('GET', '/entries', $range)['data']);
        $this->assertCount(1, $this->api('GET', '/entries', $range + ['page' => 2])['data']);
        $this->assertSame('0.026', $this->api('GET', '/summary', $range)['totals']['food_waste']['quantity']);
        foreach (['mixed_waste', 'reusable_issued'] as $type) {
            for ($index = 0; $index < 2; $index++) {
                $this->api('POST', '/entries', $this->measurement($area, [
                    'mode' => 'individual', 'measurement_type' => $type,
                    'quantity' => $type === 'mixed_waste' ? '999999999999.999' : '999999999999',
                    'destination' => $type === 'mixed_waste' ? 'unknown' : 'not_applicable',
                ]), 201);
            }
        }
        $totals = $this->api('GET', '/summary', $range)['totals'];
        $this->assertSame('1999999999999.998', $totals['mixed_waste']['quantity']);
        $this->assertSame('1999999999998', $totals['reusable_issued']['quantity']);
    }

    public function testSummaryAndRangesRetainScopeDenialsAndEmptyIntersections(): void
    {
        $area = $this->area();
        $other = $this->area('Stall B', 'Court-B');
        $this->api('POST', '/entries', $this->measurement($other), 201);
        $range = ['date_from' => '2026-01-01', 'date_to' => '2026-01-31'];
        $this->assertNull($this->api('GET', '/summary', $range + [
            'branch_ref' => 'Court-A', 'area_id' => $other['id'],
        ])['totals']['food_waste']['quantity']);
        foreach (['/summary', '/entries'] as $path) {
            $this->api('GET', $path, $range + ['branch_ref' => 'foreign'], 404);
            $this->api('GET', $path, $range + ['area_id' => $other['id'] + 99999], 404);
        }
        $this->context['branch_refs'] = ['Court-A'];
        $this->assertNull($this->api('GET', '/summary', $range)['totals']['food_waste']['quantity']);
        $this->api('GET', '/summary', $range + ['area_id' => $other['id']], 404);
        $this->context['tenant_ref'] = 'other-tenant';
        $this->api('GET', '/summary', $range + ['area_id' => $area['id']], 404);
        $this->assertNull($this->api('GET', '/summary', $range)['totals']['food_waste']['quantity']);
        $this->context['branch_refs'] = [];
        $this->api('GET', '/summary', $range, 403);
    }

    public function testValidationPreservesZeroAndRejectsFutureDatesFloatExponentOverflowAndFractionalCounts(): void
    {
        $options = $this->api('GET', '/options');
        $this->assertSame('Asia/Kuala_Lumpur', $options['reporting_timezone']);
        $this->assertSame(now('Asia/Kuala_Lumpur')->format('Y-m-d'), $options['today']);
        $area = $this->area();
        foreach (['-1', '1e3', '1.0001', '1000000000000', 1.5, 1, null, '', 'NaN'] as $quantity) {
            $this->api('POST', '/entries', $this->measurement($area, ['quantity' => $quantity]), 422);
        }
        foreach (['2026-02-30', 'next Monday', now('Asia/Kuala_Lumpur')->addDay()->format('Y-m-d')] as $date) {
            $this->api('POST', '/entries', $this->measurement($area, ['measurement_date' => $date]), 422);
        }
        $zero = $this->api('POST', '/entries', $this->measurement($area, ['quantity' => '0',
            'measurement_type' => 'mixed_waste']), 201)['entry'];
        $this->assertSame('0.000', $zero['quantity']);
        $this->assertSame('kg', $zero['unit']);
        $counts = $this->measurement($area, ['measurement_type' => 'reusable_issued', 'destination' => 'not_applicable']);
        $this->api('POST', '/entries', array_replace($counts, ['quantity' => '1.5']), 422);
        $this->api('POST', '/entries', array_replace($counts, ['quantity' => '2.000']), 422);
        $count = $this->api('POST', '/entries', array_replace($counts, ['quantity' => '999999999999']), 201)['entry'];
        $this->assertSame('999999999999', $count['quantity']);
        $this->assertSame('count', $count['unit']);
        $this->assertSame(1, $this->api('POST', '/entries', array_replace($counts, ['quantity' => '999999999999']))['entry']['version']);
        $this->api('POST', '/entries', $this->measurement($area, ['destination' => 'not_applicable']), 422);
        $this->api('POST', '/entries', $this->measurement($area, ['quantity' => '999999999999.999']), 201);
    }

    public function testModesDailyUniquenessAndRemovedUuidNeverResurrect(): void
    {
        $area = $this->area();
        $payload = $this->measurement($area);
        $entry = $this->api('POST', '/entries', $payload, 201)['entry'];
        $this->api('POST', '/entries', ['uuid' => $entry['uuid'], 'branch_ref' => $area['branch_ref']], 422);
        $conflict = $this->api('POST', '/entries', $this->measurement($area), 409);
        $this->assertSame('daily_total_exists', $conflict['code']);
        $this->assertSame($entry['uuid'], $conflict['uuid']);
        $this->api('POST', '/entries', $this->measurement($area, ['mode' => 'individual', 'destination' => 'landfill']), 409);
        $this->api('PATCH', '/entries/' . $entry['uuid'], array_replace($payload, ['version' => 1, 'mode' => 'individual']), 409);
        $this->api('DELETE', '/entries/' . $entry['uuid'], ['version' => 1]);
        $removed = $this->api('GET', '/entries/' . $entry['uuid']);
        $this->assertSame(['uuid' => $entry['uuid'], 'status' => 'removed', 'version' => 2], $removed['entry']);
        $this->api('POST', '/entries', $payload, 409);
        $this->api('PATCH', '/entries/' . $entry['uuid'], array_replace($payload, ['version' => 2]), 409);
        foreach (['1', '2'] as $quantity) {
            $this->api('POST', '/entries', $this->measurement($area, ['mode' => 'individual', 'quantity' => $quantity]), 201);
        }
        $list = $this->api('GET', '/entries', ['area_id' => $area['id']]);
        $this->assertCount(2, $list['data']);
        $this->assertSame(25, $list['per_page']);
    }

    public function testReplayUsesCurrentNormalizedFieldsAndStaleUpdatesCannotOverwrite(): void
    {
        $area = $this->area();
        $payload = $this->measurement($area, ['quantity' => '01.250', 'reference' => '  ticket  ']);
        $entry = $this->api('POST', '/entries', $payload, 201)['entry'];
        $this->assertSame(1, $this->api('POST', '/entries', $payload)['entry']['version']);
        $edit = array_replace($payload, ['version' => 1, 'quantity' => '2']);
        $this->api('PATCH', '/entries/' . $entry['uuid'], $edit);
        $this->api('POST', '/entries', $payload, 409);
        $this->api('PATCH', '/entries/' . $entry['uuid'], $edit, 409);
        $this->api('DELETE', '/entries/' . $entry['uuid'], [], 422);
        $this->api('DELETE', '/entries/' . $entry['uuid'], ['version' => 1], 409);
        $this->api('PATCH', '/entries/' . $entry['uuid'], array_replace($payload, ['version' => 2]));
        $replay = $this->api('POST', '/entries', $payload)['entry'];
        $this->assertSame(3, $replay['version']);
        $this->assertSame('1.250', $replay['quantity']);
        $this->assertSame('ticket', $replay['reference']);
    }

    public function testInactiveCorrectionsAndSameCourtMovesKeepCourtFixed(): void
    {
        $a = $this->area('A');
        $b = $this->area('B');
        $other = $this->area('Other', 'Court-B');
        $payload = $this->measurement($a);
        $entry = $this->api('POST', '/entries', $payload, 201)['entry'];
        $this->api('PATCH', '/areas/' . $a['id'], ['version' => 1, 'is_active' => false]);
        $this->api('POST', '/entries', $this->measurement($a, ['destination' => 'landfill']), 422);
        $this->api('PATCH', '/entries/' . $entry['uuid'], array_replace($payload, ['version' => 1, 'quantity' => '3']));
        $this->api('PATCH', '/entries/' . $entry['uuid'], array_replace($payload, ['version' => 2, 'area_id' => $other['id']]), 404);
        $this->api('PATCH', '/entries/' . $entry['uuid'], array_replace($payload, ['version' => 2, 'branch_ref' => 'Court-B']), 422);
        $moved = $this->api('PATCH', '/entries/' . $entry['uuid'], array_replace($payload, ['version' => 2, 'area_id' => $b['id']]))['entry'];
        $this->assertSame($b['id'], $moved['area_id']);
        $this->api('PATCH', '/entries/' . $entry['uuid'], array_replace($payload, ['version' => 3]), 422);
        $this->api('DELETE', '/entries/' . $entry['uuid'], ['version' => 3]);
    }

    public function testScopeReferencesCannotAliasThroughMysqlSpacePadding(): void
    {
        $this->assertSame(1, (int) $this->app['db']->selectOne(
            "SELECT _utf8mb4'Tenant-A' COLLATE utf8mb4_bin = _utf8mb4'Tenant-A ' AS same_scope"
        )->same_scope);
        $area = $this->area();
        $payload = $this->measurement($area);
        $entry = $this->api('POST', '/entries', $payload, 201)['entry'];
        $original = $this->context;
        foreach (['tenant_ref', 'branch_refs'] as $field) {
            foreach ([' ', "\t"] as $padding) {
                $this->context = $original;
                $this->context[$field] = $field === 'tenant_ref' ? 'Tenant-A' . $padding : ['Court-A' . $padding];
                $this->api('GET', '/entries/' . $entry['uuid'], [], 422);
                $this->api('POST', '/entries', $payload, 422);
                $this->api('PATCH', '/areas/' . $area['id'], ['version' => 1, 'is_active' => false], 422);
            }
        }
        $this->context = $original;
        config(['vmod.circularity::api.source_namespace' => 'capture-test ']);
        $this->api('GET', '/entries/' . $entry['uuid'], [], 503);
        config(['vmod.circularity::api.source_namespace' => 'capture-test']);
        $this->assertSame(1, $this->api('GET', '/entries/' . $entry['uuid'])['entry']['version']);
        $this->api('DELETE', '/entries/' . $entry['uuid'], ['version' => 1]);
        $this->context['tenant_ref'] .= ' ';
        $this->api('GET', '/entries/' . $entry['uuid'], [], 422);
    }

    public function testEveryLookupAndReplayScopesCaseSensitiveAuthorityAndActorsAreServerDerived(): void
    {
        $area = $this->area();
        $payload = $this->measurement($area, ['actor_ref' => 'forged', 'tenant_ref' => 'forged',
            'source_namespace' => 'forged', 'created_actor_ref' => 'forged', 'unit' => 'forged']);
        $entry = $this->api('POST', '/entries', $payload, 201)['entry'];
        $this->assertSame('User-A', $entry['created_actor_ref']);
        $this->assertSame('kg', $entry['unit']);
        $original = $this->context;
        foreach (['tenant' => 'tenant-a', 'branch' => 'court-a', 'namespace' => 'Capture-test'] as $kind => $value) {
            $this->context = $original;
            config(['vmod.circularity::api.source_namespace' => 'capture-test']);
            if ($kind === 'namespace') {
                config(['vmod.circularity::api.source_namespace' => $value]);
            } elseif ($kind === 'tenant') {
                $this->context['tenant_ref'] = $value;
            } else {
                $this->context['branch_refs'] = [$value];
            }
            $this->api('GET', '/entries/' . $entry['uuid'], [], 404);
            $this->api('POST', '/entries', $payload, 404);
            $this->api('PATCH', '/areas/' . $area['id'], ['version' => 1, 'is_active' => false], 404);
            $this->assertSame([], $this->api('GET', '/entries')['data']);
        }
        config(['vmod.circularity::api.source_namespace' => 'capture-test']);
        $this->context = $original;
        $this->context['permissions'] = ['circularity.read'];
        $this->api('POST', '/entries', $payload, 403);
        $this->api('GET', '/entries/' . $entry['uuid']);
        $this->context = $original;
        $this->api('DELETE', '/entries/' . $entry['uuid'], ['version' => 1]);
        $this->context['tenant_ref'] = 'another';
        $this->api('GET', '/entries/' . $entry['uuid'], [], 404);
        $this->api('POST', '/entries', $payload, 404);
    }
}
