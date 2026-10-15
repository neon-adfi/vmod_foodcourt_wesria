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
use Vmod\Erp\Services\InvoiceReferenceService;

class InvoiceReferenceTest extends ApplicationTestCase
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
        $f = (new BootstrapService)->create(['company_code' => 'ref-' . $nonce, 'company_name' => 'References ' . $nonce,
            'branch_name' => 'Main', 'first_name' => 'Owner', 'email' => $nonce . '@example.invalid',
            'password' => 'Synthetic42!password', 'password_confirmation' => 'Synthetic42!password']);
        $this->app['auth']->login($f['owner']);
        $f['role'] = $f['membership']->role;
        Workspace::clear();
        Workspace::switchTo($f['company']->id, $f['branch']->id);
        \Request::instance()->headers->set('X-Workspace-Context', Workspace::token());
        $c = new CatalogueService;
        $f['party'] = $c->saveParty(['code' => 'PARTY', 'name' => 'Supplier <script>bad()</script>', 'is_supplier' => true, 'is_customer' => true]);
        $f['product'] = $c->saveProduct(['sku' => 'RICE', 'name' => 'Rice', 'base_uom' => 'kg']);
        $f['package'] = $c->savePackage(['product_id' => $f['product']->id, 'expected_base_uom' => 'kg', 'code' => 'BAG', 'name' => 'Bag', 'conversion' => '1', 'sales_price' => '10', 'expected_currency' => 'MYR']);
        $f['offer'] = $c->saveOffer(['supplier_id' => $f['party']->id, 'package_id' => $f['package']->id, 'unit_price' => '10', 'expected_currency' => 'MYR']);
        $f['location'] = $c->saveLocation(['branch_id' => $f['branch']->id, 'code' => 'MAIN', 'name' => 'Original store']);
        $this->f = $f;
        $f['po'] = $this->order();
        return $this->f = $f;
    }

    private function order(bool $approved = true)
    {
        $s = new PurchasingService;
        $po = $s->saveDraft(['operation_key' => (string) Str::uuid(), 'branch_id' => $this->f['branch']->id,
            'supplier_id' => $this->f['party']->id, 'expected_currency' => 'MYR', 'document_date' => '2026-10-16',
            'price_mode' => 'exclusive', 'lines' => [['offer_id' => $this->f['offer']->id, 'quantity' => '10']]]);
        return $approved ? $s->approve($this->action($po)) : $po;
    }

    private function action($record, array $extra = []): array
    {
        return array_replace(['id' => $record->id, 'version' => $record->fresh()->version, 'operation_key' => (string) Str::uuid()], $extra);
    }

    private function input(array $extra = []): array
    {
        $po = $this->f['po'];
        return array_replace(['operation_key' => (string) Str::uuid(), 'branch_id' => $this->f['branch']->id,
            'direction' => 'purchase', 'party_id' => $this->f['party']->id, 'number' => 'INV-1', 'document_date' => '2026-10-16',
            'amount' => '12.34', 'links' => [['kind' => 'po', 'id' => $po->id, 'revision_id' => $po->revision_id]]], $extra);
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

    public function testReferenceLifecycleDuplicatesAndDetailedAudit(): void
    {
        $this->assertTrue(class_exists(InvoiceReferenceService::class), 'Phase 6 reference service exists');
        $f = $this->fixture();
        $s = new InvoiceReferenceService;
        $input = $this->input(['number' => '  inv-1  ', 'amount' => '0']);
        $ref = $s->save($input);
        $this->assertSame('0.00', $ref->amount);
        $this->assertSame('MYR', $ref->currency);
        $this->assertSame('inv-1', $ref->number);
        $this->assertSame($ref->id, $s->save($input)->id);
        $this->rejected(fn () => $s->save(array_replace($input, ['amount' => '1'])), 409);
        $this->rejected(fn () => $s->save($this->input()), 409);
        $edit = $this->input($this->action($ref, ['amount' => '25', 'reason' => 'Correct recorded total']));
        $ref = $s->save($edit);
        $this->assertSame('25.00', $ref->amount);
        $audit = Audit::where('action', 'invoice_reference.change')->orderByDesc('id')->first();
        $this->assertSame('0.00', $audit->details['before']['amount']);
        $this->assertSame('25.00', $audit->details['after']['amount']);
        $this->assertSame($f['owner']->id, $audit->actor_user_id);
        $this->assertSame(2, Audit::where('company_id', $f['company']->id)->where('action', 'invoice_reference.change')->count());
        $ref = $s->void($this->action($ref, ['reason' => 'Entered in error']));
        $this->rejected(fn () => $s->save($this->input($this->action($ref, ['reason' => 'Inactive edit']))), 409);
        $other = $s->save($this->input());
        $restore = $this->action($ref, ['reason' => 'Restore original']);
        $this->rejected(fn () => $s->restore($restore), 409);
        $this->assertSame('voided', $ref->fresh()->state);
        $s->void($this->action($other, ['reason' => 'Duplicate correction']));
        $ref = $s->restore($restore);
        $this->assertSame('active', $ref->state);
        $this->assertCount(1, $ref->links);
    }

    public function testStrictAmountsLinksAndFixedOwnership(): void
    {
        $this->fixture();
        $s = new InvoiceReferenceService;
        foreach (['-1', '1.001', '1e3', '100000000000000000.00', 1.0, null] as $amount) {
            $this->rejected(fn () => $s->save($this->input(['amount' => $amount])));
        }
        foreach ([[], 'bad', ['bad'], [['kind' => 'unknown', 'id' => 1, 'revision_id' => 1]]] as $links) {
            $this->rejected(fn () => $s->save($this->input(['links' => $links])));
        }
        $draft = $this->order(false);
        $this->rejected(fn () => $s->save($this->input(['links' => [['kind' => 'po', 'id' => $draft->id, 'revision_id' => $draft->revision_id]]])), 409);
        $ref = $s->save($this->input());
        $this->rejected(fn () => $s->save($this->input($this->action($ref, ['direction' => 'sales', 'reason' => 'Wrong direction']))), 409);
        $this->rejected(fn () => $s->save($this->input($this->action($ref))));
        $this->rejected(fn () => $s->save($this->input($this->action($ref, ['links' => [], 'reason' => 'Remove all']))));
    }

    public function testPinnedRevisionsRetainedLinksAndRemovedLinkAudit(): void
    {
        $f = $this->fixture();
        $s = new InvoiceReferenceService;
        $po2 = $this->order();
        $input = $this->input();
        $input['links'][] = ['kind' => 'po', 'id' => $po2->id, 'revision_id' => $po2->revision_id];
        $ref = $s->save($input);
        $oldRevision = $f['po']->revision_id;
        (new PurchasingService)->reopen($this->action($f['po'], ['reason' => 'New revision']));
        $ref = $s->save(array_replace($input, $this->action($ref), ['amount' => '20.00', 'reason' => 'Metadata only']));
        $this->assertSame($oldRevision, $ref->links->first()->purchase_revision_id);
        $ref = $s->void($this->action($ref, ['reason' => 'Pause reference']));
        $ref = $s->restore($this->action($ref, ['reason' => 'Restore retained history']));
        $this->assertCount(2, $ref->links);
        $this->rejected(fn () => $s->save($this->input(['number' => 'NEW'])), 409);
        $ref = $s->save($this->input($this->action($ref, ['reason' => 'Remove second order'])));
        $audit = Audit::where('action', 'invoice_reference.change')->orderByDesc('id')->first();
        $this->assertCount(1, $audit->details['removed']);
        $this->assertCount(1, $ref->links);
    }

    public function testFulfilmentSalesCurrencyAndCrossBranchDuplicates(): void
    {
        $f = $this->fixture();
        $s = new InvoiceReferenceService;
        $r = new \Vmod\Erp\Services\ReceivingService;
        $sales = new \Vmod\Erp\Services\SalesService;
        $dispatch = new \Vmod\Erp\Services\DispatchService;
        $receipt = $r->saveDraft(['operation_key' => (string) Str::uuid(), 'purchase_order_id' => $f['po']->id,
            'supplier_reference' => 'DEL-1', 'document_date' => '2026-10-16',
            'lines' => [['purchase_order_item_id' => $f['po']->revision->items->first()->id, 'location_id' => $f['location']->id, 'quantity' => '10']]]);
        $receiptLink = ['kind' => 'grn', 'id' => $receipt->id, 'revision_id' => $receipt->revision_id];
        $this->rejected(fn () => $s->save($this->input(['links' => [$receiptLink]])), 409);
        $receipt = $r->confirm($this->action($receipt));
        $purchase = $s->save($this->input(['links' => [$receiptLink]]));
        $so = $sales->saveDraft(['operation_key' => (string) Str::uuid(), 'branch_id' => $f['branch']->id,
            'customer_id' => $f['party']->id, 'expected_currency' => 'MYR', 'document_date' => '2026-10-16', 'price_mode' => 'exclusive',
            'lines' => [['package_id' => $f['package']->id, 'location_id' => $f['location']->id, 'quantity' => '6']]]);
        $so = $sales->approve($this->action($so));
        $delivery = $dispatch->saveDraft(['operation_key' => (string) Str::uuid(), 'sales_order_id' => $so->id,
            'document_date' => '2026-10-16', 'lines' => [['sales_order_item_id' => $so->revision->items->first()->id, 'quantity' => '6']]]);
        $salesInput = $this->input(['direction' => 'sales', 'links' => [['kind' => 'so', 'id' => $so->id, 'revision_id' => $so->revision_id],
            ['kind' => 'do', 'id' => $delivery->id, 'revision_id' => $delivery->revision_id]]]);
        $this->rejected(fn () => $s->save($salesInput), 409);
        $delivery = $dispatch->dispatch($this->action($delivery));
        $ref = $s->save($salesInput);
        $this->assertCount(2, $ref->links);
        $this->assertSame('sales', $ref->direction); // Same number in the other direction is a distinct reference.
        $dispatch->correct($this->action($delivery, ['reason' => 'Reverse delivery', 'lines' => []]));
        $ref = $s->save(array_replace($salesInput, $this->action($ref), ['reason' => 'Retain corrected original']));
        $this->assertSame($delivery->id, $ref->links->whereNotNull('delivery_order_id')->first()->delivery_order_id);
        $this->assertSame('voided', collect($s->linkViews($ref))->firstWhere('kind', 'do')['state']);
        $sales->closeShort($this->action($so, ['reason' => 'Release restored reservation']));
        $r->correct($this->action($receipt, ['reason' => 'Reverse receipt', 'lines' => []]));
        $purchase = $s->restore($this->action($s->void($this->action($purchase, ['reason' => 'Pause'])), ['reason' => 'Restore history']));
        $this->assertSame($receipt->id, $purchase->links->first()->goods_receipt_id);
        $po2 = $this->order();
        DB::table('vmod_erp_purchase_order_revisions')->where('id', $po2->revision_id)->update(['currency' => 'USD']);
        $this->rejected(fn () => $s->save($this->input(['number' => 'Mixed', 'links' => [
            ['kind' => 'po', 'id' => $f['po']->id, 'revision_id' => $f['po']->revision_id],
            ['kind' => 'po', 'id' => $po2->id, 'revision_id' => $po2->revision_id],
        ]])), 409);
        $branch = (new \Vmod\Core\Services\AdministrationService)->saveBranch(['name' => 'Second']);
        Workspace::switchTo($f['company']->id, $branch->id);
        \Request::instance()->headers->set('X-Workspace-Context', Workspace::token());
        $this->f['branch'] = $branch;
        $po3 = $this->order();
        $this->rejected(fn () => $s->save($this->input(['branch_id' => $branch->id,
            'links' => [['kind' => 'po', 'id' => $po3->id, 'revision_id' => $po3->revision_id]]])), 409);
        $distinct = $s->save($this->input(['number' => 'INV -1', 'branch_id' => $branch->id,
            'links' => [['kind' => 'po', 'id' => $po3->id, 'revision_id' => $po3->revision_id]]]));
        $this->assertSame('INV -1', $distinct->number); // Internal whitespace remains meaningful.
    }

    public function testScopeRevokedReplayAndAuditFailureRollBack(): void
    {
        $f = $this->fixture();
        $s = new InvoiceReferenceService;
        $input = $this->input();
        $ref = $s->save($input);
        \Request::instance()->headers->set('X-Workspace-Context', 'stale');
        $this->rejected(fn () => $s->save($input), 409);
        \Request::instance()->headers->set('X-Workspace-Context', Workspace::token());
        $f['role']->permissions = ['invoice_reference.read'];
        $f['role']->save();
        $this->rejected(fn () => $s->save($input), 403);
        $this->assertSame($ref->id, $s->find($ref->id)->id);
        $f['role']->permissions = \Vmod\Core\Classes\Permissions::all();
        $f['role']->save();
        $before = DB::table('vmod_erp_invoice_references')->count();
        Audit::creating(function ($audit) {
            if ($audit->action === 'invoice_reference.change') {
                throw new \RuntimeException('Synthetic audit failure');
            }
        });
        try {
            $s->save($this->input(['number' => 'ROLLBACK']));
            $this->fail('Audit failure must propagate');
        } catch (\RuntimeException $e) {
            $this->assertSame('Synthetic audit failure', $e->getMessage());
        } finally {
            Audit::flushEventListeners();
        }
        $this->assertSame($before, DB::table('vmod_erp_invoice_references')->count());
        $foreign = $this->fixture();
        $this->rejected(fn () => $s->find($ref->id), 404);
        $this->rejected(fn () => $s->save($this->input(['links' => $input['links']])), 404);
    }
}
