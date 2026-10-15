<?php namespace Vmod\Erp\Tests;

use DB;
use Illuminate\Support\Str;
use October\Rain\Exception\ValidationException;
use Vmod\Tests\ApplicationTestCase;
use Vmod\Core\Classes\Workspace;
use Vmod\Core\Models\Audit;
use Vmod\Core\Services\BootstrapService;
use Vmod\Erp\Services\CatalogueService;
use Vmod\Erp\Services\SalesService;
use Vmod\Erp\Services\DispatchService;
use Vmod\Erp\Services\StockService;
use Vmod\Erp\Models\StockBalance;
use Vmod\Erp\Models\StockMovement;
use Vmod\Erp\Models\StockReservation;

class SalesReservationTest extends ApplicationTestCase
{
    protected $autoMigrate = false;
    private $f;

    public function setUp(): void
    {
        parent::setUp();
        $this->app->make(\Illuminate\Contracts\Console\Kernel::class)->call('october:migrate', ['--no-interaction' => true]);
        DB::beginTransaction();
    }

    public function tearDown(): void
    {
        $this->app['auth']->logout();
        while (DB::transactionLevel()) {
            DB::rollBack();
        }
        parent::tearDown();
    }

    private function fixture(): array
    {
        $nonce = bin2hex(random_bytes(6));
        $f = (new BootstrapService)->create(['company_code' => 'so-' . $nonce, 'company_name' => 'Sales ' . $nonce,
            'branch_name' => 'Main', 'first_name' => 'Owner', 'email' => $nonce . '@example.invalid',
            'password' => 'Synthetic42!password', 'password_confirmation' => 'Synthetic42!password']);
        $this->app['auth']->login($f['owner']);
        Workspace::clear();
        Workspace::switchTo($f['company']->id, $f['branch']->id);
        \Request::instance()->headers->set('X-Workspace-Context', Workspace::token());
        $c = new CatalogueService;
        $f['customer'] = $c->saveParty(['code' => 'CUS', 'name' => 'Customer', 'is_supplier' => false, 'is_customer' => true]);
        $f['product'] = $c->saveProduct(['sku' => 'RICE', 'name' => 'Rice', 'base_uom' => 'kg']);
        $f['package'] = $c->savePackage(['product_id' => $f['product']->id, 'expected_base_uom' => 'kg', 'code' => 'PACK',
            'name' => 'Pack', 'conversion' => '1', 'sales_price' => '10', 'expected_currency' => 'MYR']);
        $f['tax'] = $c->saveTaxRate(['name' => 'Tax', 'rate' => '6']);
        $f['location'] = $c->saveLocation(['branch_id' => $f['branch']->id, 'code' => 'MAIN', 'name' => 'Main']);
        (new StockService)->open(['operation_key' => (string) Str::uuid(), 'branch_id' => $f['branch']->id,
            'location_id' => $f['location']->id, 'product_id' => $f['product']->id, 'quantity' => '10', 'expected_base_uom' => 'kg', 'reason' => 'Opening']);
        return $this->f = $f;
    }

    private function input(array $extra = []): array
    {
        return array_replace(['operation_key' => (string) Str::uuid(), 'branch_id' => $this->f['branch']->id,
            'customer_id' => $this->f['customer']->id, 'expected_currency' => 'MYR', 'document_date' => '2026-10-15',
            'price_mode' => 'exclusive', 'lines' => [['package_id' => $this->f['package']->id, 'location_id' => $this->f['location']->id,
                'quantity' => '6', 'tax_rate_id' => $this->f['tax']->id]]], $extra);
    }

    private function action($record, array $extra = []): array
    {
        return array_replace(['id' => $record->id, 'version' => $record->fresh()->version, 'operation_key' => (string) Str::uuid()], $extra);
    }

    private function rejected(callable $call, int $status = 422): void
    {
        try {
            $call();
            $this->fail('Operation must reject.');
        } catch (ValidationException $e) {
            $this->assertSame(422, $status);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            $this->assertSame(404, $status);
        } catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $e) {
            $this->assertSame($status, $e->getStatusCode());
        }
    }

    private function delivery($so, string $quantity)
    {
        return (new DispatchService)->saveDraft(['operation_key' => (string) Str::uuid(), 'sales_order_id' => $so->id,
            'document_date' => '2026-10-15', 'lines' => [['sales_order_item_id' => $so->revision->items->first()->id, 'quantity' => $quantity]]]);
    }

    private function reconciled(string $onHand, string $reserved, int $movements): void
    {
        $balance = StockBalance::where('company_id', $this->f['company']->id)->sole();
        $this->assertSame($onHand, $balance->on_hand);
        $this->assertSame($reserved, $balance->reserved);
        $this->assertSame($onHand, (string) \Brick\Math\BigDecimal::of((string) StockMovement::where('company_id', $balance->company_id)->sum('quantity'))->toScale(4));
        $sum = DB::table('vmod_erp_stock_reservations')->where('company_id', $balance->company_id)->selectRaw('COALESCE(SUM(approved - dispatched - released), 0) AS total')->first()->total;
        $this->assertSame($reserved, (string) \Brick\Math\BigDecimal::of((string) $sum)->toScale(4));
        $this->assertSame($movements, StockMovement::where('company_id', $balance->company_id)->count());
    }

    public function testNativeSalesComponentIsRegistered(): void
    {
        $plugin = new \Vmod\Erp\Plugin($this->app);
        $this->assertSame('sales', $plugin->registerComponents()[\Vmod\Erp\Components\Sales::class] ?? null);
        $this->assertArrayNotHasKey(\Vmod\Erp\Components\Sales::class, $plugin->pluginDetails());
    }

    public function testSalesSnapshotsPricingEligibilityAndRevisions(): void
    {
        $this->assertTrue(class_exists(SalesService::class), 'Phase 5 sales service exists');
        $f = $this->fixture();
        $s = new SalesService;
        $input = $this->input();
        $so = $s->saveDraft($input);
        $this->assertSame('63.60', $so->revision->gross);
        $this->assertSame($so->id, $s->saveDraft($input)->id);
        $this->rejected(fn () => $s->saveDraft(array_replace($input, ['notes' => 'Changed retry'])), 409);
        $first = $so->revision;
        (new CatalogueService)->savePackage(['id' => $f['package']->id, 'version' => $f['package']->version,
            'code' => 'PACK', 'name' => 'Changed pack', 'sales_price' => '12', 'expected_currency' => 'MYR']);
        $so = $s->saveDraft($this->input($this->action($so, ['price_mode' => 'inclusive',
            'lines' => [['item_id' => $first->items->first()->id, 'location_id' => $f['location']->id, 'quantity' => '6']]])));
        $this->assertSame('10.6000', $so->revision->items->first()->snapshot['unit_price']);
        $this->assertSame('Pack', $so->revision->items->first()->snapshot['package_name']);
        $so = $s->repriceDraft($this->action($so, ['item_ids' => [$so->revision->items->first()->id]]));
        $this->assertSame('12.7200', $so->revision->items->first()->snapshot['unit_price']);
        $this->assertSame('10.0000', $first->fresh()->items->first()->snapshot['unit_price']);
        $this->assertSame('draft', $so->state);
    }

    public function testReserveDispatchAndReleaseWorkedExample(): void
    {
        $this->assertTrue(class_exists(DispatchService::class), 'Phase 5 dispatch service exists');
        $this->fixture();
        $s = new SalesService;
        $d = new DispatchService;
        $so = $s->saveDraft($this->input());
        $this->reconciled('10.0000', '0.0000', 1);
        $approve = $this->action($so);
        $so = $s->approve($approve);
        $this->assertSame($so->id, $s->approve($approve)->id);
        $this->reconciled('10.0000', '6.0000', 1);
        $delivery = $this->delivery($so, '2');
        $dispatch = $this->action($delivery);
        $delivery = $d->dispatch($dispatch);
        $this->assertSame($delivery->id, $d->dispatch($dispatch)->id);
        $this->reconciled('8.0000', '4.0000', 2);
        $this->assertSame('partially_delivered', $so->fresh()->state);
        $s->closeShort($this->action($so, ['reason' => 'Customer cancelled remainder']));
        $this->reconciled('8.0000', '0.0000', 2);
        $this->rejected(fn () => $d->correct($this->action($delivery, ['reason' => 'Too late', 'lines' => []])), 409);
    }

    public function testCorrectionRestoresReservationsAndRetainsHistoricalFreeze(): void
    {
        $this->assertTrue(class_exists(DispatchService::class), 'Phase 5 dispatch service exists');
        $this->fixture();
        $s = new SalesService;
        $d = new DispatchService;
        $so = $s->approve($this->action($s->saveDraft($this->input())));
        $delivery = $d->dispatch($this->action($this->delivery($so, '6')));
        $this->assertSame('completed', $so->fresh()->state);
        $this->reconciled('4.0000', '0.0000', 2);
        $replacement = $d->correct($this->action($delivery, ['reason' => 'Only two shipped', 'document_date' => '2026-10-15',
            'lines' => [['sales_order_item_id' => $so->revision->items->first()->id, 'quantity' => '2']]]));
        $this->reconciled('8.0000', '4.0000', 4);
        $this->assertSame('partially_delivered', $so->fresh()->state);
        $d->correct($this->action($replacement, ['reason' => 'Entirely entered in error', 'lines' => []]));
        $this->reconciled('10.0000', '6.0000', 5);
        $this->assertNotNull($so->fresh()->first_dispatch_at);
        $this->assertSame('approved', $so->fresh()->state);
        $this->rejected(fn () => $s->reopen($this->action($so, ['reason' => 'Cannot reset history'])), 409);
        $s->closeShort($this->action($so, ['reason' => 'Cancel restored demand']));
        $this->reconciled('10.0000', '0.0000', 5);
    }

    public function testApprovalRequiresCurrentMastersButDispatchHonorsRetirement(): void
    {
        $f = $this->fixture();
        $c = new CatalogueService;
        $s = new SalesService;
        $blank = $c->savePackage(['product_id' => $f['product']->id, 'expected_base_uom' => 'kg', 'code' => 'BLANK', 'name' => 'Unpriced', 'conversion' => '1']);
        $input = $this->input();
        $input['lines'][0]['package_id'] = $blank->id;
        $input['lines'][0] += ['price_action' => 'override', 'unit_price' => '5', 'price_reason' => 'Cannot bypass missing catalogue price'];
        $this->rejected(fn () => $s->saveDraft($input));
        $so = $s->saveDraft($this->input());
        foreach (['parties' => $f['customer'], 'products' => $f['product'], 'packages' => $f['package'], 'locations' => $f['location']] as $kind => $master) {
            $c->setActive($kind, ['id' => $master->id, 'version' => $master->fresh()->version, 'is_active' => false]);
            $this->rejected(fn () => $s->approve($this->action($so)), 409);
            $this->reconciled('10.0000', '0.0000', 1);
            $c->setActive($kind, ['id' => $master->id, 'version' => $master->fresh()->version, 'is_active' => true]);
        }
        $c->savePackage(['id' => $f['package']->id, 'version' => $f['package']->fresh()->version, 'code' => 'PACK', 'name' => 'Pack', 'sales_price' => '']);
        $this->rejected(fn () => $s->approve($this->action($so)), 409);
        $c->savePackage(['id' => $f['package']->id, 'version' => $f['package']->fresh()->version, 'code' => 'PACK', 'name' => 'Pack', 'sales_price' => '0', 'expected_currency' => 'MYR']);
        $zero = $s->saveDraft($this->input());
        $this->assertSame('0.00', $zero->revision->gross);
        $so = $s->approve($this->action($so));
        $this->assertSame('10.0000', $so->revision->items->first()->snapshot['unit_price']);
        foreach (['parties' => $f['customer'], 'products' => $f['product'], 'packages' => $f['package'], 'locations' => $f['location']] as $kind => $master) {
            $c->setActive($kind, ['id' => $master->id, 'version' => $master->fresh()->version, 'is_active' => false]);
        }
        $d = new DispatchService;
        $delivery = $d->dispatch($this->action($this->delivery($so, '2')));
        $this->reconciled('8.0000', '4.0000', 2);
        $d->correct($this->action($delivery, ['reason' => 'Reverse at retired source', 'lines' => []]));
        $this->reconciled('10.0000', '6.0000', 3);
    }

    public function testPriceAnchorsStrictDecimalsAndRequiredDraftLocations(): void
    {
        $f = $this->fixture();
        $s = new SalesService;
        $input = $this->input(['price_mode' => 'inclusive']);
        $input['lines'][0] += ['price_action' => 'override', 'unit_price' => '12.3456'];
        $this->rejected(fn () => $s->saveDraft($input));
        $input['lines'][0]['price_reason'] = 'Customer agreed price';
        $so = $s->saveDraft($input);
        foreach (['exclusive', 'inclusive', 'exclusive', 'inclusive'] as $mode) {
            $so = $s->saveDraft($this->input($this->action($so, ['price_mode' => $mode, 'lines' => [[
                'item_id' => $so->revision->items->first()->id, 'location_id' => $f['location']->id, 'quantity' => '6']]])));
        }
        $this->assertSame('12.3456', $so->revision->items->first()->snapshot['unit_price']);
        $rate = (new CatalogueService)->saveTaxRate(['name' => 'Eight', 'rate' => '8']);
        $so = $s->saveDraft($this->input($this->action($so, ['price_mode' => 'inclusive', 'lines' => [[
            'item_id' => $so->revision->items->first()->id, 'location_id' => $f['location']->id, 'quantity' => '6', 'tax_rate_id' => $rate->id]]])));
        $this->assertSame('12.5785', $so->revision->items->first()->snapshot['unit_price']);
        $this->assertSame('12.3456', $so->revision->items->first()->snapshot['source_price']);
        foreach ([1.2, '1.00001', '-1', '1e2', ['1'], '1000000000000000'] as $quantity) {
            $bad = $this->input();
            $bad['lines'][0]['quantity'] = $quantity;
            $this->rejected(fn () => $s->saveDraft($bad));
        }
        $bad = $this->input();
        unset($bad['lines'][0]['location_id']);
        $this->rejected(fn () => $s->saveDraft($bad));
        $this->rejected(fn () => $s->saveDraft($this->input(['lines' => ['bad' => ['quantity' => '1']]])));
        $this->rejected(fn () => $s->saveDraft($this->input(['lines' => ['bad']])));
        $fraction = (new CatalogueService)->savePackage(['product_id' => $f['product']->id, 'expected_base_uom' => 'kg',
            'code' => 'FRACTION', 'name' => 'Fraction', 'conversion' => '0.3333', 'sales_price' => '1', 'expected_currency' => 'MYR']);
        $bad = $this->input();
        $bad['lines'][0]['package_id'] = $fraction->id;
        $bad['lines'][0]['quantity'] = '0.3333';
        $this->rejected(fn () => $s->saveDraft($bad));
        $bad = $this->input();
        $bad['lines'][0] += ['price_action' => 'override', 'unit_price' => '999999999999999.9999', 'price_reason' => 'Overflow'];
        $bad['lines'][0]['quantity'] = '999999999999999.9999';
        $this->rejected(fn () => $s->saveDraft($bad));
        $stale = $this->action($so);
        $s->approve($stale);
        $this->rejected(fn () => $s->approve(array_replace($stale, ['operation_key' => (string) Str::uuid()])), 409);
    }

    public function testDuplicateLinesAggregateApprovalAndRejectWrongOrderConsumption(): void
    {
        $this->fixture();
        $s = new SalesService;
        $d = new DispatchService;
        $input = $this->input();
        $input['lines'][] = $input['lines'][0];
        $so = $s->saveDraft($input);
        $this->rejected(fn () => $s->approve($this->action($so)), 409);
        $this->assertSame('draft', $so->fresh()->state);
        $this->assertSame(0, StockReservation::where('company_id', $this->f['company']->id)->count());
        $this->reconciled('10.0000', '0.0000', 1);
        $input['lines'][0]['quantity'] = '4';
        $so = $s->saveDraft(array_replace($input, $this->action($so)));
        $so = $s->approve($this->action($so));
        $this->reconciled('10.0000', '10.0000', 1);
        $this->assertSame(2, StockReservation::where('sales_order_id', $so->id)->count());
        $lines = $so->revision->items;
        $delivery = $d->saveDraft(['operation_key' => (string) Str::uuid(), 'sales_order_id' => $so->id, 'document_date' => '2026-10-15',
            'lines' => [['sales_order_item_id' => $lines[0]->id, 'quantity' => '2'], ['sales_order_item_id' => $lines[1]->id, 'quantity' => '2']]]);
        $d->dispatch($this->action($delivery));
        $this->reconciled('6.0000', '6.0000', 3);
        $excess = $this->delivery($so, '3');
        $this->rejected(fn () => $d->dispatch($this->action($excess)), 409);
        $this->assertSame('draft', $excess->fresh()->state);
        $other = $s->saveDraft($this->input());
        $this->rejected(fn () => $d->saveDraft(['operation_key' => (string) Str::uuid(), 'sales_order_id' => $so->id,
            'document_date' => '2026-10-15', 'lines' => [['sales_order_item_id' => $other->revision->items->first()->id, 'quantity' => '1']]]), 404);
        $d->dispatch($this->action($this->delivery($so, '1')));
        $d->dispatch($this->action($this->delivery($so, '1')));
        $this->reconciled('4.0000', '4.0000', 5);
    }

    public function testReopenVoidsDraftsReleasesHistoryAndReapprovalUsesNewItems(): void
    {
        $this->fixture();
        $s = new SalesService;
        $d = new DispatchService;
        $so = $s->approve($this->action($s->saveDraft($this->input())));
        $old = $so->revision->items->first();
        $pending = $this->delivery($so, '2');
        $so = $s->reopen($this->action($so, ['reason' => '  Customer changed demand  ']));
        $this->assertSame('voided', $pending->fresh()->state);
        $this->assertSame('Customer changed demand', Audit::where('company_id', $this->f['company']->id)->where('action', 'salesorder.draft')->latest('id')->firstOrFail()->details['reason']);
        $this->assertSame('6.0000', StockReservation::where('sales_order_item_id', $old->id)->sole()->released);
        $this->reconciled('10.0000', '0.0000', 1);
        $this->rejected(fn () => $d->dispatch($this->action($pending)), 409);
        $so = $s->approve($this->action($so));
        $this->assertNotSame($old->id, $so->revision->items->first()->id);
        $this->rejected(fn () => $d->saveDraft(['operation_key' => (string) Str::uuid(), 'sales_order_id' => $so->id,
            'document_date' => '2026-10-15', 'lines' => [['sales_order_item_id' => $old->id, 'quantity' => '1']]]), 404);
        $this->reconciled('10.0000', '6.0000', 1);
        $pending = $this->delivery($so, '1');
        $void = $this->action($so, ['reason' => 'Cancelled before dispatch']);
        $s->void($void);
        $s->void($void);
        $this->assertSame('voided', $pending->fresh()->state);
        $this->reconciled('10.0000', '0.0000', 1);
        $this->rejected(fn () => $d->dispatch($this->action($pending)), 409);
    }

    private function rollbackCheck(callable $call): void
    {
        $snapshot = function () {
            $data = [];
            foreach (['sales_orders', 'sales_order_revisions', 'sales_order_items', 'delivery_orders', 'delivery_order_items',
                'stock_reservations', 'stock_balances', 'stock_movements', 'operations'] as $table) {
                $data[$table] = DB::table('vmod_erp_' . $table)->orderBy('id')->get()->all();
            }
            $data['counters'] = DB::table('vmod_erp_document_counters')->orderBy('company_id')->orderBy('type')->get()->all();
            return hash('sha256', json_encode($data));
        };
        $before = $snapshot();
        \Event::listen('eloquent.creating: ' . Audit::class, function () {
            throw new \RuntimeException('Sales audit rejected');
        });
        try {
            $call();
            $this->fail('Audit failure must roll back the complete operation.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Sales audit rejected', $e->getMessage());
        } finally {
            \Event::forget('eloquent.creating: ' . Audit::class);
        }
        $this->assertSame($before, $snapshot());
    }

    public function testAuditFailureRollsBackApprovalReleaseDispatchAndCorrection(): void
    {
        $this->fixture();
        $s = new SalesService;
        $d = new DispatchService;
        $so = $s->saveDraft($this->input());
        $this->rollbackCheck(fn () => $s->approve($this->action($so)));
        $so = $s->approve($this->action($so));
        $draft = $this->delivery($so, '2');
        $this->rollbackCheck(fn () => $s->void($this->action($so, ['reason' => 'Audit release failure'])));
        $this->rollbackCheck(fn () => $d->dispatch($this->action($draft)));
        $delivery = $d->dispatch($this->action($draft));
        $correction = ['reason' => 'Replace quantity', 'document_date' => '2026-10-15', 'lines' => [[
            'sales_order_item_id' => $so->revision->items->first()->id, 'quantity' => '1']]];
        $this->rollbackCheck(fn () => $d->correct($this->action($delivery, $correction)));
        $this->reconciled('8.0000', '4.0000', 2);
        $this->rejected(fn () => $d->correct($this->action($delivery, array_replace($correction, ['lines' => [[
            'sales_order_item_id' => $so->revision->items->first()->id, 'quantity' => '7']]]))), 409);
        $this->reconciled('8.0000', '4.0000', 2);
        $correct = $this->action($delivery, $correction);
        $replacement = $d->correct($correct);
        $this->assertSame($replacement->id, $d->correct($correct)->id);
        $this->rejected(fn () => $d->correct(array_replace($correct, ['reason' => 'Changed key content'])), 409);
        $this->reconciled('9.0000', '5.0000', 4);
    }

    public function testReservationsProtectAdjustmentsAndNetReceiptCorrections(): void
    {
        $f = $this->fixture();
        $c = new CatalogueService;
        $supplier = $c->saveParty(['code' => 'SUP', 'name' => 'Supplier', 'is_supplier' => true, 'is_customer' => false]);
        $offer = $c->saveOffer(['supplier_id' => $supplier->id, 'package_id' => $f['package']->id, 'unit_price' => '1', 'expected_currency' => 'MYR']);
        $p = new \Vmod\Erp\Services\PurchasingService;
        $r = new \Vmod\Erp\Services\ReceivingService;
        $po = $p->saveDraft(['operation_key' => (string) Str::uuid(), 'branch_id' => $f['branch']->id, 'supplier_id' => $supplier->id,
            'expected_currency' => 'MYR', 'document_date' => '2026-10-15', 'price_mode' => 'exclusive', 'lines' => [['offer_id' => $offer->id, 'quantity' => '5']]]);
        $po = $p->approve($this->action($po));
        $grn = $r->saveDraft(['operation_key' => (string) Str::uuid(), 'purchase_order_id' => $po->id, 'supplier_reference' => 'SUP-1',
            'document_date' => '2026-10-15', 'lines' => [['purchase_order_item_id' => $po->revision->items->first()->id, 'location_id' => $f['location']->id, 'quantity' => '5']]]);
        $grn = $r->confirm($this->action($grn));
        $stock = new StockService;
        $adjust = ['branch_id' => $f['branch']->id, 'product_id' => $f['product']->id, 'location_id' => $f['location']->id,
            'expected_base_uom' => 'kg', 'direction' => 'decrease', 'quantity' => '5', 'reason' => 'Count correction'];
        $stock->adjust($adjust + ['operation_key' => (string) Str::uuid()]);
        $s = new SalesService;
        $so = $s->approve($this->action($s->saveDraft($this->input())));
        $this->reconciled('10.0000', '6.0000', 3);
        $this->rejected(fn () => $stock->adjust($adjust + ['operation_key' => (string) Str::uuid()]), 409);
        $this->rejected(fn () => $r->correct($this->action($grn, ['reason' => 'Reserved stock cannot be reversed', 'lines' => []])), 409);
        $stock->adjust(array_replace($adjust, ['operation_key' => (string) Str::uuid(), 'quantity' => '4']));
        $this->reconciled('6.0000', '6.0000', 4);
        $stock->adjust(array_replace($adjust, ['operation_key' => (string) Str::uuid(), 'quantity' => '4', 'direction' => 'increase']));
        $grn = $r->correct($this->action($grn, ['reason' => 'Net reduction four', 'document_date' => '2026-10-15', 'supplier_reference' => 'SUP-1-FIX',
            'lines' => [['purchase_order_item_id' => $po->revision->items->first()->id, 'location_id' => $f['location']->id, 'quantity' => '1']]]));
        $this->reconciled('6.0000', '6.0000', 7);
        $this->rejected(fn () => $r->correct($this->action($grn, ['reason' => 'No free stock', 'lines' => []])), 409);
        $s->void($this->action($so, ['reason' => 'Release demand']));
        $r->correct($this->action($grn, ['reason' => 'Now available', 'lines' => []]));
        $this->reconciled('5.0000', '0.0000', 8);
    }
    public function testSplitLocationsCorrectionsAndBalanceOverflow(): void
    {
        $f = $this->fixture();
        $s = new SalesService;
        $d = new DispatchService;
        $other = (new CatalogueService)->saveLocation(['branch_id' => $f['branch']->id, 'code' => 'OTHER', 'name' => 'Other']);
        $stock = ['operation_key' => (string) Str::uuid(), 'branch_id' => $f['branch']->id, 'product_id' => $f['product']->id,
            'location_id' => $other->id, 'quantity' => '10', 'expected_base_uom' => 'kg', 'reason' => 'Other opening'];
        (new StockService)->open($stock);
        $input = $this->input();
        $input['lines'][] = array_replace($input['lines'][0], ['location_id' => $other->id, 'quantity' => '4']);
        $so = $s->approve($this->action($s->saveDraft($input)));
        $items = $so->revision->items;
        $delivery = $d->dispatch($this->action($this->delivery($so, '2')));
        $replacement = $d->correct($this->action($delivery, ['reason' => 'Correct approved line', 'document_date' => '2026-10-15',
            'lines' => [['sales_order_item_id' => $items[1]->id, 'quantity' => '3', 'location_id' => $f['location']->id, 'actor_user_id' => 999]] ]));
        $this->assertSame($other->id, $replacement->items->first()->location_id);
        foreach ([[$f['location']->id, '10.0000', '6.0000'], [$other->id, '7.0000', '1.0000']] as [$id, $physical, $reserved]) {
            $balance = StockBalance::where('location_id', $id)->sole();
            $this->assertSame($physical, $balance->on_hand);
            $this->assertSame($reserved, $balance->reserved);
            $this->assertSame($physical, (string) \Brick\Math\BigDecimal::of((string) StockMovement::where('location_id', $id)->sum('quantity'))->toScale(4));
        }
        $stock['operation_key'] = (string) Str::uuid();
        $stock['quantity'] = '999999999999992.9999';
        $stock['direction'] = 'increase';
        (new StockService)->adjust($stock);
        $this->rejected(fn () => $d->correct($this->action($replacement, ['reason' => 'Would overflow physical stock', 'lines' => []])));
        $this->assertSame('dispatched', $replacement->fresh()->state);
        $this->assertSame('3.0000', StockReservation::where('sales_order_item_id', $items[1]->id)->sole()->dispatched);
        $duplicates = DB::table('vmod_erp_stock_movements')->whereNotNull('delivery_item_id')->groupBy('delivery_item_id', 'action')->havingRaw('COUNT(*) > 1')->get();
        $this->assertCount(0, $duplicates);
    }

    public function testScopedPermissionsForeignIdsRevokedReplayAndServerActor(): void
    {
        $one = $this->fixture();
        $s = new SalesService;
        $d = new DispatchService;
        $so = $s->saveDraft($this->input());
        $two = $this->fixture();
        $foreign = $s->saveDraft($this->input());
        $this->f = $one;
        $this->app['auth']->login($one['owner']);
        Workspace::switchTo($one['company']->id, $one['branch']->id);
        \Request::instance()->headers->set('X-Workspace-Context', Workspace::token());
        $bad = $this->input();
        $bad['lines'][0]['package_id'] = $two['package']->id;
        $this->rejected(fn () => $s->saveDraft($bad), 404);
        $bad['lines'][0]['package_id'] = $one['package']->id;
        $bad['lines'][0]['location_id'] = $two['location']->id;
        $this->rejected(fn () => $s->saveDraft($bad), 404);
        $this->rejected(fn () => $s->find($foreign->id), 404);
        $this->rejected(fn () => $s->saveDraft($this->input(['branch_id' => $two['branch']->id])), 404);
        $this->rejected(fn () => $s->saveDraft($this->input($this->action($so, ['customer_id' => $two['customer']->id]))), 409);
        $role = \Vmod\Core\Models\Role::create(['company_id' => $one['company']->id, 'name' => 'Sales only', 'permissions' => ['salesorder.read', 'salesorder.write']]);
        $member = \Vmod\Core\Models\Membership::create(['company_id' => $one['company']->id, 'user_id' => $two['owner']->id, 'role_id' => $role->id]);
        $member->branches()->attach($one['branch']->id);
        $this->app['auth']->login($two['owner']);
        Workspace::switchTo($one['company']->id, $one['branch']->id);
        \Request::instance()->headers->set('X-Workspace-Context', Workspace::token());
        $input = $this->input(['actor_user_id' => $one['owner']->id, 'reserved' => '999']);
        $saved = $s->saveDraft($input);
        $this->assertSame($two['owner']->id, $saved->revision->actor_user_id);
        $this->rejected(fn () => $s->approve([]), 403);
        $this->rejected(fn () => $d->dispatch([]), 403);
        $role->permissions = ['salesorder.approve'];
        $role->save();
        $approve = $this->action($saved);
        $saved = $s->approve($approve); // Approval does not require write, read, catalogue or inventory grants.
        $this->rejected(fn () => $s->reopen($this->action($saved, ['reason' => 'Missing write'])), 403);
        $this->rejected(fn () => $s->saveDraft($input), 403);
        $role->permissions = ['deliveryorder.write', 'deliveryorder.dispatch', 'deliveryorder.correct'];
        $role->save();
        $this->rejected(fn () => $s->approve($approve), 403);
        $delivery = $this->delivery($saved, '2');
        $dispatch = $this->action($delivery);
        $d->dispatch($dispatch);
        $this->assertSame($delivery->id, $d->dispatch($dispatch)->id);
        $role->permissions = ['deliveryorder.read'];
        $role->save();
        $this->rejected(fn () => $d->dispatch($dispatch), 403);
        $this->assertSame($delivery->id, $d->find($delivery->id)->id);
        $member->branches()->detach();
        $this->rejected(fn () => $d->find($delivery->id), 403);
        \Request::instance()->headers->remove('X-Workspace-Context');
        $this->rejected(fn () => $s->saveDraft([]), 409);
        $this->rejected(fn () => $d->dispatch([]), 409);
    }
}
