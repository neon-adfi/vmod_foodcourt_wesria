<?php namespace Vmod\Erp\Tests;

use DB;
use Illuminate\Support\Str;
use October\Rain\Exception\ValidationException;
use Vmod\Tests\ApplicationTestCase;
use Vmod\Core\Classes\Workspace;
use Vmod\Core\Models\Audit;
use Vmod\Core\Services\BootstrapService;
use Vmod\Erp\Services\CatalogueService;
use Vmod\Erp\Services\PurchasingService;
use Vmod\Erp\Services\ReceivingService;
use Vmod\Erp\Services\StockService;
use Vmod\Erp\Models\PurchaseOrder;
use Vmod\Erp\Models\StockBalance;
use Vmod\Erp\Models\StockMovement;

class PurchasingStockTest extends ApplicationTestCase
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
        $f = (new BootstrapService)->create(['company_code' => 'po-' . $nonce, 'company_name' => 'Purchasing ' . $nonce,
            'branch_name' => 'Main', 'first_name' => 'Owner', 'email' => $nonce . '@example.invalid',
            'password' => 'Synthetic42!password', 'password_confirmation' => 'Synthetic42!password']);
        $this->app['auth']->login($f['owner']);
        Workspace::clear();
        Workspace::switchTo($f['company']->id, $f['branch']->id);
        \Request::instance()->headers->set('X-Workspace-Context', Workspace::token());
        $c = new CatalogueService;
        $f['supplier'] = $c->saveParty(['code' => 'SUP', 'name' => 'Supplier', 'is_supplier' => true, 'is_customer' => false]);
        $f['product'] = $c->saveProduct(['sku' => 'RICE', 'name' => 'Rice', 'base_uom' => 'kg']);
        $f['package'] = $c->savePackage(['product_id' => $f['product']->id, 'expected_base_uom' => 'kg', 'code' => 'BAG', 'name' => 'Bag', 'conversion' => '2']);
        $f['offer'] = $c->saveOffer(['supplier_id' => $f['supplier']->id, 'package_id' => $f['package']->id, 'unit_price' => '10', 'expected_currency' => 'MYR']);
        $f['tax'] = $c->saveTaxRate(['name' => 'Tax', 'rate' => '6']);
        $f['location'] = $c->saveLocation(['branch_id' => $f['branch']->id, 'code' => 'MAIN', 'name' => 'Main']);
        return $this->f = $f;
    }

    private function input(array $extra = []): array
    {
        return array_replace(['operation_key' => (string) Str::uuid(), 'branch_id' => $this->f['branch']->id,
            'supplier_id' => $this->f['supplier']->id, 'expected_currency' => 'MYR', 'document_date' => '2026-10-15',
            'price_mode' => 'exclusive', 'lines' => [['offer_id' => $this->f['offer']->id, 'quantity' => '3', 'tax_rate_id' => $this->f['tax']->id]]], $extra);
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

    public function testDraftSnapshotsModesRepricingAndFrozenApproval(): void
    {
        $this->assertTrue(class_exists(PurchasingService::class), 'Phase 4 purchasing service exists');
        $f = $this->fixture();
        $p = new PurchasingService;
        $input = $this->input();
        $po = $p->saveDraft($input);
        $this->assertSame('30.00', $po->revision->net);
        $this->assertSame('1.80', $po->revision->tax);
        $this->assertSame($po->id, $p->saveDraft($input)->id);
        $this->rejected(fn () => $p->saveDraft(array_replace($input, ['document_date' => '2026-10-16'])), 409);
        $first = $po->revision;
        $line = $first->items->first();
        (new CatalogueService)->saveOffer(['id' => $f['offer']->id, 'version' => $f['offer']->version, 'unit_price' => '12', 'expected_currency' => 'MYR']);
        $edit = $this->input($this->action($po, ['lines' => [['item_id' => $line->id, 'quantity' => '3']], 'price_mode' => 'inclusive']));
        $po = $p->saveDraft($edit);
        $this->assertSame('10.6000', $po->revision->items->first()->snapshot['unit_price']);
        $this->assertSame('31.80', $po->revision->gross);
        $this->assertSame('10.0000', $first->fresh()->items->first()->snapshot['unit_price']);
        $po = $p->repriceDraft($this->action($po, ['item_ids' => [$po->revision->items->first()->id]]));
        $this->assertSame('12.7200', $po->revision->items->first()->snapshot['unit_price']);
        $po = $p->approve($this->action($po));
        $this->assertSame('approved', $po->state);
        $this->rejected(fn () => $p->saveDraft($this->input($this->action($po))), 409);
        $po = $p->reopen($this->action($po, ['reason' => 'Change quantity']));
        $this->assertSame('draft', $po->state);
        $this->assertSame('Change quantity', Audit::where('company_id', $f['company']->id)->where('action', 'purchaseorder.draft')->latest('id')->firstOrFail()->details['reason'] ?? null);
        $this->assertNotSame($first->id, $po->revision_id);
    }

    private function receipt($po, string $quantity, string $reference)
    {
        return (new ReceivingService)->saveDraft(['operation_key' => (string) Str::uuid(), 'purchase_order_id' => $po->id,
            'document_date' => '2026-10-15', 'supplier_reference' => $reference, 'lines' => [[
                'purchase_order_item_id' => $po->revision->items->first()->id, 'location_id' => $this->f['location']->id, 'quantity' => $quantity]]]);
    }

    private function reconciled(string $quantity): void
    {
        $this->assertSame($quantity, StockBalance::where('company_id', $this->f['company']->id)->sole()->on_hand);
        $sum = DB::table('vmod_erp_stock_movements')->where('company_id', $this->f['company']->id)->sum('quantity');
        $this->assertSame($quantity, (string) \Brick\Math\BigDecimal::of((string) $sum)->toScale(4));
    }

    public function testReceiptsCorrectionsReferenceClaimsAndHistoricalFreeze(): void
    {
        $this->assertTrue(class_exists(ReceivingService::class), 'Phase 4 receiving service exists');
        $this->fixture();
        $p = new PurchasingService;
        $r = new ReceivingService;
        $po = $p->saveDraft($this->input());
        $po = $p->approve($this->action($po));
        $grn = $this->receipt($po, '1', 'DO-A');
        $confirm = $this->action($grn);
        $grn = $r->confirm($confirm);
        $this->assertSame($grn->id, $r->confirm($confirm)->id);
        $this->assertSame('partially_received', $po->fresh()->state);
        $this->reconciled('2.0000');
        $full = $r->confirm($this->action($this->receipt($po, '2', 'DO-B')));
        $this->assertSame('completed', $po->fresh()->state);
        $this->reconciled('6.0000');
        $this->rejected(fn () => $r->confirm($this->action($this->receipt($po, '1', 'DO-C'))), 409);
        $replacement = $r->correct($this->action($full, ['reason' => 'Quantity and reference typo', 'supplier_reference' => 'DO-B-FIX',
            'document_date' => '2026-10-15', 'lines' => [['purchase_order_item_id' => $po->revision->items->first()->id,
                'location_id' => $this->f['location']->id, 'quantity' => '1']]]));
        $this->reconciled('4.0000');
        $this->assertSame('partially_received', $po->fresh()->state);
        $this->rejected(fn () => $r->confirm($this->action($this->receipt($po, '1', ' do-b '))), 409);
        $r->correct($this->action($replacement, ['reason' => 'Never received', 'lines' => []]));
        $r->correct($this->action($grn, ['reason' => 'Never received', 'lines' => []]));
        $this->reconciled('0.0000');
        $this->assertSame('approved', $po->fresh()->state);
        $this->assertNotNull($po->fresh()->first_received_at);
        $this->rejected(fn () => $p->reopen($this->action($po, ['reason' => 'Attempt history reset'])), 409);
        $p->closeShort($this->action($po, ['reason' => 'No remaining delivery']));
        $this->assertSame('closed_short', $po->fresh()->state);
    }

    public function testOpeningAdjustmentsUomAndAuditRollback(): void
    {
        $this->assertTrue(class_exists(StockService::class), 'Phase 4 stock service exists');
        $f = $this->fixture();
        $product = (new CatalogueService)->saveProduct(['sku' => 'LOOSE', 'name' => 'Loose', 'base_uom' => 'kg']);
        $s = new StockService;
        $data = ['operation_key' => (string) Str::uuid(), 'branch_id' => $f['branch']->id, 'location_id' => $f['location']->id,
            'product_id' => $product->id, 'expected_base_uom' => 'kg', 'quantity' => '5', 'reason' => 'Opening count'];
        $movement = $s->open($data);
        $this->assertSame($movement->id, $s->open($data)->id);
        $this->rejected(fn () => $s->open(array_replace($data, ['operation_key' => (string) Str::uuid()])), 409);
        $this->rejected(fn () => (new CatalogueService)->saveProduct(['id' => $product->id, 'version' => $product->version,
            'sku' => 'LOOSE', 'name' => 'Loose', 'base_uom' => 'g']), 409);
        $adjust = array_replace($data, ['operation_key' => (string) Str::uuid(), 'direction' => 'decrease', 'quantity' => '6']);
        $this->rejected(fn () => $s->adjust($adjust), 409);
        \Event::listen('eloquent.creating: ' . Audit::class, function () {
            throw new \RuntimeException('Audit rejected');
        });
        try {
            $s->adjust(array_replace($adjust, ['quantity' => '2']));
            $this->fail('Audit must roll back stock.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Audit rejected', $e->getMessage());
        } finally {
            \Event::forget('eloquent.creating: ' . Audit::class);
        }
        $this->reconciled('5.0000');
        $this->assertSame(1, StockMovement::where('company_id', $f['company']->id)->count());
    }

    public function testManualInclusiveAnchorAndStrictBoundaryValidation(): void
    {
        $this->fixture();
        $p = new PurchasingService;
        $override = $this->input(['price_mode' => 'inclusive', 'lines' => [['offer_id' => $this->f['offer']->id,
            'quantity' => '3', 'tax_rate_id' => $this->f['tax']->id, 'price_action' => 'override', 'unit_price' => '10']]]);
        $this->rejected(fn () => $p->saveDraft($override));
        $override['lines'][0]['price_reason'] = 'Supplier agreed inclusive quote';
        $po = $p->saveDraft($override);
        $this->assertSame('28.30', $po->revision->net);
        $this->assertSame('1.70', $po->revision->tax);
        $this->assertSame('30.00', $po->revision->gross);
        foreach (['exclusive', 'inclusive', 'exclusive', 'inclusive'] as $mode) {
            $po = $p->saveDraft($this->input($this->action($po, ['price_mode' => $mode,
                'lines' => [['item_id' => $po->revision->items->first()->id, 'quantity' => '3']]])));
        }
        $this->assertSame('10.0000', $po->revision->items->first()->snapshot['unit_price']);
        $this->assertSame('10.0000', $po->revision->items->first()->snapshot['source_price']);
        $rate = (new CatalogueService)->saveTaxRate(['name' => 'Eight', 'rate' => '8']);
        $po = $p->saveDraft($this->input($this->action($po, ['price_mode' => 'inclusive',
            'lines' => [['item_id' => $po->revision->items->first()->id, 'quantity' => '3', 'tax_rate_id' => $rate->id]]])));
        $this->assertSame('10.1887', $po->revision->items->first()->snapshot['unit_price']);
        foreach ([1.2, '1.00001', '-1', '1e2', ['1'], '1000000000000000'] as $quantity) {
            $bad = $this->input();
            $bad['lines'][0]['quantity'] = $quantity;
            $this->rejected(fn () => $p->saveDraft($bad));
        }
        $this->rejected(fn () => $p->saveDraft($this->input(['lines' => ['bad' => ['quantity' => '1']]])));
        $this->rejected(fn () => $p->saveDraft($this->input(['lines' => ['bad']])));
        $stale = $this->action($po);
        $p->approve($stale);
        $this->rejected(fn () => $p->approve(array_replace($stale, ['operation_key' => (string) Str::uuid()])), 409);
    }

    public function testNetCorrectionSafetyRetirementAndTransactionalRollback(): void
    {
        $f = $this->fixture();
        $p = new PurchasingService;
        $r = new ReceivingService;
        $po = $p->approve($this->action($p->saveDraft($this->input())));
        $grn = $r->confirm($this->action($this->receipt($po, '3', 'COMPLETE')));
        (new StockService)->adjust(['operation_key' => (string) Str::uuid(), 'branch_id' => $f['branch']->id,
            'product_id' => $f['product']->id, 'location_id' => $f['location']->id, 'expected_base_uom' => 'kg',
            'direction' => 'decrease', 'quantity' => '4', 'reason' => 'Count correction']);
        $replacement = ['reason' => 'One package fewer', 'supplier_reference' => 'COMPLETE-FIX', 'document_date' => '2026-10-15',
            'lines' => [['purchase_order_item_id' => $po->revision->items->first()->id, 'location_id' => $f['location']->id, 'quantity' => '2']]];
        // Full reversal would need six units, but the atomic net correction needs only two.
        $grn = $r->correct($this->action($grn, $replacement));
        $this->reconciled('0.0000');
        $count = StockMovement::count();
        $this->rejected(fn () => $r->correct($this->action($grn, ['reason' => 'Unsafe full reversal', 'lines' => []])), 409);
        $this->assertSame('confirmed', $grn->fresh()->state);
        $this->assertSame($count, StockMovement::count());
        $c = new CatalogueService;
        foreach (['parties' => $f['supplier'], 'products' => $f['product'], 'packages' => $f['package'], 'tax_rates' => $f['tax']] as $kind => $master) {
            $c->setActive($kind, ['id' => $master->id, 'version' => $master->fresh()->version, 'is_active' => false]);
        }
        $draft = $this->receipt($po, '1', 'RETIRED-SAVED');
        $before = [StockMovement::count(), DB::table('vmod_erp_delivery_references')->count(), DB::table('vmod_erp_operations')->count()];
        \Event::listen('eloquent.creating: ' . Audit::class, function () {
            throw new \RuntimeException('Receipt audit rejected');
        });
        try {
            $r->confirm($this->action($draft));
            $this->fail('Confirmation must roll back on audit failure.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Receipt audit rejected', $e->getMessage());
        } finally {
            \Event::forget('eloquent.creating: ' . Audit::class);
        }
        $this->assertSame($before, [StockMovement::count(), DB::table('vmod_erp_delivery_references')->count(), DB::table('vmod_erp_operations')->count()]);
        $this->assertSame('draft', $draft->fresh()->state);
        $r->confirm($this->action($draft));
        $this->reconciled('2.0000');
        $this->assertSame('completed', $po->fresh()->state);
    }

    public function testReopeningVoidsDraftsAndManualClosureBlocksConfirmation(): void
    {
        $this->fixture();
        $p = new PurchasingService;
        $r = new ReceivingService;
        $po = $p->approve($this->action($p->saveDraft($this->input())));
        $draft = $this->receipt($po, '1', 'PENDING');
        $po = $p->reopen($this->action($po, ['reason' => 'Buyer change']));
        $this->assertSame('voided', $draft->fresh()->state);
        $this->rejected(fn () => $r->confirm($this->action($draft)), 409);
        $po = $p->approve($this->action($po));
        $partial = $r->confirm($this->action($this->receipt($po, '1', 'PARTIAL')));
        $pending = $this->receipt($po, '2', 'PENDING-2');
        $p->closeShort($this->action($po, ['reason' => 'Supplier cannot fulfil']));
        $this->assertSame('voided', $pending->fresh()->state);
        $this->rejected(fn () => $r->confirm($this->action($pending)), 409);
        $this->rejected(fn () => $r->correct($this->action($partial, ['reason' => 'Too late', 'lines' => []])), 409);
        $this->reconciled('2.0000');
    }

    public function testScopedPermissionsForeignIdsAndRevokedReplay(): void
    {
        $one = $this->fixture();
        $p = new PurchasingService;
        $po = $p->saveDraft($this->input());
        $two = $this->fixture();
        $foreignOffer = $two['offer'];
        $foreignLocation = $two['location'];
        $this->f = $one;
        $this->app['auth']->login($one['owner']);
        Workspace::switchTo($one['company']->id, $one['branch']->id);
        \Request::instance()->headers->set('X-Workspace-Context', Workspace::token());
        $bad = $this->input();
        $bad['lines'][0]['offer_id'] = $foreignOffer->id;
        $this->rejected(fn () => $p->saveDraft($bad), 404);
        $this->rejected(fn () => $p->saveDraft($this->input(['branch_id' => $two['branch']->id])), 404);
        $this->rejected(fn () => $p->saveDraft($this->input($this->action($po, ['supplier_id' => $two['supplier']->id]))), 409);
        $po = $p->approve($this->action($po));
        $this->rejected(fn () => (new ReceivingService)->saveDraft(['operation_key' => (string) Str::uuid(), 'purchase_order_id' => $po->id,
            'supplier_reference' => 'BAD', 'document_date' => '2026-10-15', 'lines' => [['purchase_order_item_id' => $po->revision->items->first()->id,
                'location_id' => $foreignLocation->id, 'quantity' => '1']]]), 404);
        $role = \Vmod\Core\Models\Role::create(['company_id' => $one['company']->id, 'name' => 'Buyer only', 'permissions' => ['purchaseorder.read', 'purchaseorder.write']]);
        $member = \Vmod\Core\Models\Membership::create(['company_id' => $one['company']->id, 'user_id' => $two['owner']->id, 'role_id' => $role->id]);
        $member->branches()->attach($one['branch']->id);
        $this->app['auth']->login($two['owner']);
        Workspace::switchTo($one['company']->id, $one['branch']->id);
        \Request::instance()->headers->set('X-Workspace-Context', Workspace::token());
        $input = $this->input();
        $saved = $p->saveDraft($input); // No catalogue.read grant is needed.
        $this->assertSame('draft', $saved->state);
        $this->rejected(fn () => $p->approve([]), 403);
        $this->rejected(fn () => (new ReceivingService)->confirm([]), 403);
        $this->rejected(fn () => (new StockService)->adjust([]), 403);
        $role->permissions = ['purchaseorder.read'];
        $role->save();
        $this->rejected(fn () => $p->saveDraft($input), 403);
        \Request::instance()->headers->remove('X-Workspace-Context');
        $this->rejected(fn () => $p->saveDraft([]), 409);
        $this->rejected(fn () => (new ReceivingService)->confirm([]), 409);
        $this->rejected(fn () => (new StockService)->adjust([]), 409);
    }
}
