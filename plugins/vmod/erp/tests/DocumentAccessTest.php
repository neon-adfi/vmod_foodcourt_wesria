<?php namespace Vmod\Erp\Tests;

use DB;
use Illuminate\Support\Str;
use Vmod\Tests\ApplicationTestCase;
use Vmod\Core\Classes\Workspace;
use Vmod\Erp\Components\DocumentPrint;

class DocumentAccessTest extends ApplicationTestCase
{
    protected $autoMigrate = false;

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

    private function action($record, array $extra = []): array
    {
        return array_replace(['id' => $record->id, 'version' => $record->fresh()->version, 'operation_key' => (string) Str::uuid()], $extra);
    }

    private function fixture(): array
    {
        $n = bin2hex(random_bytes(5));
        $f = (new \Vmod\Core\Services\BootstrapService)->create(['company_code' => 'print-' . $n, 'company_name' => 'Original issuer',
            'branch_name' => 'Original branch', 'first_name' => 'Owner', 'email' => $n . '@example.invalid',
            'password' => 'Synthetic42!password', 'password_confirmation' => 'Synthetic42!password']);
        $this->app['auth']->login($f['owner']);
        Workspace::clear();
        Workspace::switchTo($f['company']->id, $f['branch']->id);
        \Request::instance()->headers->set('X-Workspace-Context', Workspace::token());
        $c = new \Vmod\Erp\Services\CatalogueService;
        $f['party'] = $c->saveParty(['code' => 'SUP', 'name' => 'Original <supplier>', 'is_supplier' => true, 'is_customer' => true]);
        $product = $c->saveProduct(['sku' => 'RICE', 'name' => 'Original rice', 'base_uom' => 'kg']);
        $f['package'] = $c->savePackage(['product_id' => $product->id, 'expected_base_uom' => 'kg', 'code' => 'BAG', 'name' => 'Bag', 'conversion' => '1', 'sales_price' => '10', 'expected_currency' => 'MYR']);
        $offer = $c->saveOffer(['supplier_id' => $f['party']->id, 'package_id' => $f['package']->id, 'unit_price' => '10', 'expected_currency' => 'MYR']);
        $f['location'] = $c->saveLocation(['branch_id' => $f['branch']->id, 'code' => 'MAIN', 'name' => 'Original location']);
        $f['po'] = (new \Vmod\Erp\Services\PurchasingService)->saveDraft(['operation_key' => (string) Str::uuid(), 'branch_id' => $f['branch']->id,
            'supplier_id' => $f['party']->id, 'expected_currency' => 'MYR', 'document_date' => '2026-10-16', 'price_mode' => 'exclusive',
            'lines' => [['offer_id' => $offer->id, 'quantity' => '10']]]);
        return $f;
    }

    public function testHistoricalPrintSnapshotsAndQuantityOnlyFulfilment(): void
    {
        $this->assertTrue(class_exists(DocumentPrint::class), 'Phase 6 protected print component exists');
        $f = $this->fixture();
        $p = new \Vmod\Erp\Services\PurchasingService;
        $r = new \Vmod\Erp\Services\ReceivingService;
        $print = new DocumentPrint;
        $po = $p->approve($this->action($f['po']));
        $old = $po->revision;
        $po = $p->reopen($this->action($po, ['reason' => 'Revised order']));
        $f['company']->update(['name' => 'Renamed issuer']);
        $f['branch']->update(['name' => 'Renamed branch']);
        $history = $print->document('po', $po->id, $old->id);
        $this->assertTrue($history['historical']);
        $this->assertTrue($history['approved']);
        $this->assertSame('Original issuer', $history['issuer']['company']);
        $this->assertSame('Original branch', $history['issuer']['branch']);
        $this->assertSame('100.00', $history['totals']['gross']);
        $this->assertSame('Original issuer', $po->revision->issuer_snapshot['company']);
        $po = $p->approve($this->action($po));
        $grn = $r->saveDraft(['operation_key' => (string) Str::uuid(), 'purchase_order_id' => $po->id, 'supplier_reference' => 'SUP-DO',
            'document_date' => '2026-10-16', 'lines' => [['purchase_order_item_id' => $po->revision->items->first()->id, 'location_id' => $f['location']->id, 'quantity' => '10']]]);
        $grn = $r->confirm($this->action($grn));
        $f['location']->update(['name' => 'Renamed location']);
        $receipt = $print->document('grn', $grn->id);
        $this->assertFalse($receipt['commercial']);
        $this->assertArrayNotHasKey('totals', $receipt);
        $this->assertArrayNotHasKey('unit_price', $receipt['lines'][0]);
        $this->assertSame('Original location', $receipt['lines'][0]['location']);
        $this->assertSame('10.0000', $receipt['lines'][0]['quantity']);
        DB::table('vmod_erp_purchase_order_revisions')->where('id', $old->id)->update(['issuer_snapshot' => null]);
        $legacy = $print->document('po', $po->id, $old->id);
        $this->assertTrue($legacy['issuer_missing']);
        $this->assertSame('Renamed issuer', $legacy['issuer']['company']);
        DB::table('vmod_erp_goods_receipt_items')->where('goods_receipt_id', $grn->id)->update(['location_name' => null]);
        $this->assertTrue($print->document('grn', $grn->id)['lines'][0]['location_missing']);
    }

    public function testSalesPrintAndCorrectedDeliveryKeepOriginalSnapshots(): void
    {
        $f = $this->fixture();
        (new \Vmod\Erp\Services\StockService)->open(['operation_key' => (string) Str::uuid(), 'branch_id' => $f['branch']->id,
            'product_id' => $f['package']->product_id, 'location_id' => $f['location']->id, 'expected_base_uom' => 'kg', 'quantity' => '10', 'reason' => 'Opening']);
        $s = new \Vmod\Erp\Services\SalesService;
        $d = new \Vmod\Erp\Services\DispatchService;
        $so = $s->saveDraft(['operation_key' => (string) Str::uuid(), 'branch_id' => $f['branch']->id, 'customer_id' => $f['party']->id,
            'document_date' => '2026-10-16', 'expected_currency' => 'MYR', 'price_mode' => 'inclusive',
            'lines' => [['package_id' => $f['package']->id, 'location_id' => $f['location']->id, 'quantity' => '4']]]);
        $so = $s->approve($this->action($so));
        $delivery = $d->saveDraft(['operation_key' => (string) Str::uuid(), 'sales_order_id' => $so->id, 'document_date' => '2026-10-16',
            'lines' => [['sales_order_item_id' => $so->revision->items->first()->id, 'quantity' => '4']]]);
        $delivery = $d->dispatch($this->action($delivery));
        $replacement = $d->correct($this->action($delivery, ['reason' => 'Wrong quantity', 'document_date' => '2026-10-16',
            'lines' => [['sales_order_item_id' => $so->revision->items->first()->id, 'quantity' => '2']]]));
        $f['company']->update(['name' => 'Renamed company']);
        $f['location']->update(['name' => 'Renamed location']);
        $print = new DocumentPrint;
        $order = $print->document('so', $so->id);
        $this->assertSame('Original issuer', $order['issuer']['company']);
        $this->assertSame('inclusive', $order['price_mode']);
        $this->assertSame('40.00', $order['totals']['gross']);
        $original = $print->document('do', $delivery->id);
        $this->assertSame('voided', $original['state']);
        $this->assertSame($replacement->number, $original['replacement']);
        $this->assertSame('4.0000', $original['lines'][0]['quantity']);
        $this->assertSame('Original location', $original['lines'][0]['location']);
        $this->assertArrayNotHasKey('totals', $original);
        $this->assertArrayNotHasKey('unit_price', $original['lines'][0]);
        $this->assertSame('2.0000', $print->document('do', $replacement->id)['lines'][0]['quantity']);
    }

    public function testDocumentPermissionParentRevisionAndForeignScope(): void
    {
        $f = $this->fixture();
        $print = new DocumentPrint;
        $foreign = $this->fixture();
        $this->app['auth']->login($f['owner']);
        Workspace::switchTo($f['company']->id, $f['branch']->id);
        foreach ([fn () => $print->document('po', $foreign['po']->id), fn () => $print->document('po', $f['po']->id, $foreign['po']->revision_id)] as $call) {
            try {
                $call();
                $this->fail('Foreign record must not render');
            } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
                $this->assertTrue(true);
            }
        }
        $role = $f['membership']->role;
        $role->permissions = ['invoice_reference.read', 'invoice_reference.write'];
        $role->save();
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        $print->document('po', $f['po']->id);
    }
}
