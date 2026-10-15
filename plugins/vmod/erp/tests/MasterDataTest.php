<?php namespace Vmod\Erp\Tests;

use Vmod\Tests\ApplicationTestCase;
use Vmod\Core\Classes\Workspace;
use Vmod\Core\Services\BootstrapService;
use Vmod\Core\Services\AdministrationService;
use Vmod\Core\Models\Membership;
use Vmod\Core\Models\Audit;
use Vmod\Erp\Services\CatalogueService;
use Vmod\Erp\Models\Product;
use Vmod\Erp\Models\ProductPackage;
use October\Rain\Exception\ValidationException;

class MasterDataTest extends ApplicationTestCase
{
    protected $autoMigrate = false;

    public function setUp(): void
    {
        parent::setUp();
        $this->app->make(\Illuminate\Contracts\Console\Kernel::class)->call('october:migrate', ['--no-interaction' => true]);
        $this->app['db']->beginTransaction();
    }

    public function tearDown(): void
    {
        if ($this->app['auth']->user()) {
            $this->app['auth']->logout();
        }
        while ($this->app['db']->transactionLevel()) {
            $this->app['db']->rollBack();
        }
        parent::tearDown();
    }

    private function company(): array
    {
        $nonce = bin2hex(random_bytes(6));
        return (new BootstrapService)->create([
            'company_code' => 'erp-' . $nonce, 'company_name' => 'ERP ' . $nonce,
            'branch_name' => 'Main', 'first_name' => 'Owner', 'email' => $nonce . '@example.invalid',
            'password' => 'Synthetic42!password', 'password_confirmation' => 'Synthetic42!password',
        ]);
    }

    private function acting(array $company, $user = null): void
    {
        $this->app['auth']->logout();
        $this->app['auth']->login(($user ?: $company['owner'])->fresh());
        Workspace::clear();
        Workspace::switchTo($company['company']->id, $company['branch']->id);
        \Request::instance()->headers->set('X-Workspace-Context', Workspace::token());
    }

    private function rejected(callable $call, int $status = 422): void
    {
        try {
            $call();
            $this->fail('Operation must be rejected.');
        } catch (ValidationException $exception) {
            $this->assertSame(422, $status);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $exception) {
            $this->assertSame(404, $status);
        } catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $exception) {
            $this->assertSame($status, $exception->getStatusCode());
        }
    }

    private function product(CatalogueService $service)
    {
        return $service->saveProduct(['sku' => 'RICE', 'name' => 'Rice', 'base_uom' => 'kg']);
    }

    private function package(CatalogueService $service, $product, $price = null)
    {
        return $service->savePackage(['product_id' => $product->id, 'expected_base_uom' => $product->base_uom, 'code' => 'BAG', 'name' => 'Bag', 'conversion' => '2.5', 'sales_price' => $price, 'expected_currency' => 'MYR']);
    }

    public function testUnpricedPackageLocksUnitButOnlyAnActualPriceLocksCurrency(): void
    {
        $this->assertTrue(class_exists(CatalogueService::class), 'Catalogue service must exist.');
        $company = $this->company();
        $this->acting($company);
        $service = new CatalogueService;
        $product = $this->product($service);
        $package = $this->package($service, $product);
        $this->assertNull($package->sales_price);
        $this->assertNull($company['company']->fresh()->currency_locked_at);
        $this->rejected(fn () => $service->saveProduct(['id' => $product->id, 'version' => $product->version, 'sku' => 'RICE', 'name' => 'Rice', 'base_uom' => 'g']), 409);
        (new AdministrationService)->saveCompany(['name' => 'Renamed', 'currency' => 'SGD', 'expected_currency' => 'MYR']);
        $this->assertSame('SGD', $company['company']->fresh()->currency);
        $data = ['id' => $package->id, 'version' => $package->version, 'name' => 'Bag', 'code' => 'BAG', 'sales_price' => '0', 'expected_currency' => 'MYR'];
        $this->rejected(fn () => $service->savePackage($data), 409);
        $package = $service->savePackage(array_replace($data, ['expected_currency' => 'SGD']));
        $this->assertSame('0.0000', $package->sales_price);
        $this->assertNotNull($company['company']->fresh()->currency_locked_at);
        $package = $service->savePackage(array_replace($data, ['version' => $package->version, 'expected_currency' => 'SGD', 'sales_price' => '']));
        $this->assertNull($package->sales_price);
        $service->setActive('packages', ['id' => $package->id, 'version' => $package->version, 'is_active' => false]);
        $this->rejected(fn () => (new AdministrationService)->saveCompany(['name' => 'Renamed', 'currency' => 'MYR', 'expected_currency' => 'SGD']), 409);
    }

    public function testOffersSnapshotsAndRetirementDoNotRewriteHistory(): void
    {
        $company = $this->company();
        $this->acting($company);
        $service = new CatalogueService;
        $party = $service->saveParty(['code' => 'SUP', 'name' => 'Supplier', 'is_supplier' => true, 'is_customer' => true]);
        $product = $this->product($service);
        $package = $this->package($service, $product);
        $rate = $service->saveTaxRate(['name' => 'Example', 'rate' => '6']);
        $unpriced = $service->snapshot(['party_id' => $party->id, 'package_id' => $package->id, 'quantity' => '1']);
        $this->assertNull($unpriced['line']['unit_price']);
        $this->rejected(fn () => $service->snapshot(['party_id' => $party->id, 'package_id' => $package->id, 'quantity' => '1', 'offer_id' => []]));
        $offer = $service->saveOffer(['supplier_id' => $party->id, 'package_id' => $package->id, 'unit_price' => '10', 'expected_currency' => 'MYR']);
        $this->assertNotNull($company['company']->fresh()->currency_locked_at);
        $input = ['party_id' => $party->id, 'package_id' => $package->id, 'offer_id' => $offer->id, 'tax_rate_id' => $rate->id, 'quantity' => '3'];
        $snapshot = json_decode(json_encode($service->snapshot($input), JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('7.5000', $snapshot['line']['base_quantity']);
        $this->assertSame('10.0000', $snapshot['line']['unit_price']);
        $service->saveOffer(['id' => $offer->id, 'version' => $offer->version, 'unit_price' => '11', 'expected_currency' => 'MYR']);
        $service->saveTaxRate(['id' => $rate->id, 'version' => $rate->version, 'name' => 'Changed', 'rate' => '8']);
        $service->savePackage(['id' => $package->id, 'version' => $package->version, 'code' => 'BAG', 'name' => 'Renamed bag', 'sales_price' => null, 'expected_currency' => 'MYR']);
        $this->assertSame('10.0000', $snapshot['line']['unit_price']);
        $this->assertSame('6.0000', $snapshot['line']['tax_rate']);
        $this->assertSame('Bag', $snapshot['line']['package_name']);
        $this->assertSame('Supplier', $snapshot['party']['name']);
        $this->rejected(fn () => $service->saveOffer(['supplier_id' => $party->id, 'package_id' => $package->id, 'unit_price' => '1', 'expected_currency' => 'MYR']));
        $service->setActive('parties', ['id' => $party->id, 'version' => $party->version, 'is_active' => false]);
        $this->rejected(fn () => $service->snapshot($input));
        $this->assertSame(0, $service->listing('offers')->total());
        $this->assertTrue((bool) $offer->fresh()->is_active);
        $service->setActive('parties', ['id' => $party->id, 'version' => $party->fresh()->version, 'is_active' => true]);
        $this->assertSame(1, $service->listing('offers')->total());
        $service->setActive('offers', ['id' => $offer->id, 'version' => $offer->fresh()->version, 'is_active' => false]);
        $this->assertSame(0, $service->listing('offers')->total());
        $this->rejected(fn () => $service->saveOffer(['supplier_id' => $party->id, 'package_id' => $package->id, 'unit_price' => '1', 'expected_currency' => 'MYR']));
        $this->rejected(fn () => $service->snapshot($input));
        $audit = Audit::where('company_id', $company['company']->id)->where('action', 'catalogue.offers.updated')->firstOrFail();
        $this->assertSame((int) $company['owner']->id, (int) $audit->actor_user_id);
        $this->assertSame('10.0000', $audit->details['before']['unit_price']);
        $this->assertSame('11.0000', $audit->details['after']['unit_price']);
        $service->setActive('products', ['id' => $product->id, 'version' => $product->version, 'is_active' => false]);
        $this->assertSame(0, $service->listing('packages')->total());
        $this->rejected(fn () => $this->package($service, $product));
        $edited = $service->savePackage(['id' => $package->id, 'version' => $package->fresh()->version, 'code' => 'BAG', 'name' => 'Editable while unavailable', 'sales_price' => null]);
        $this->assertSame('Editable while unavailable', $edited->name);
        $editedOffer = $service->saveOffer(['id' => $offer->id, 'version' => $offer->fresh()->version, 'unit_price' => '12', 'expected_currency' => 'MYR']);
        $this->assertSame('12.0000', $editedOffer->unit_price);
        $this->assertFalse($editedOffer->is_active);
        $service->setActive('products', ['id' => $product->id, 'version' => $product->fresh()->version, 'is_active' => true]);
        $this->assertSame(1, $service->listing('packages')->total());
        $this->assertSame(0, $service->listing('offers')->total(), 'Restoring a parent must not restore an explicitly inactive offer.');
    }

    public function testTenantBranchPermissionsContextAndImmutableOwnership(): void
    {
        $one = $this->company();
        $two = $this->company();
        $service = new CatalogueService;
        $this->acting($two);
        $foreign = $this->product($service);
        $foreignParty = $service->saveParty(['code' => 'FOREIGN', 'name' => 'Foreign supplier', 'is_supplier' => true, 'is_customer' => true]);
        $foreignPackage = $this->package($service, $foreign);
        $foreignOffer = $service->saveOffer(['supplier_id' => $foreignParty->id, 'package_id' => $foreignPackage->id, 'unit_price' => '10', 'expected_currency' => 'MYR']);
        $service->setActive('offers', ['id' => $foreignOffer->id, 'version' => $foreignOffer->version, 'is_active' => false]);
        $this->acting($one);
        $product = $this->product($service);
        $this->assertSame(1, $service->listing('products')->total());
        $this->rejected(fn () => $service->find('products', $foreign->id), 404);
        $this->rejected(fn () => $this->package($service, $foreign), 404);
        $this->rejected(fn () => $service->saveLocation(['branch_id' => $two['branch']->id, 'code' => 'MAIN', 'name' => 'Foreign']), 404);
        $package = $this->package($service, $product);
        $party = $service->saveParty(['code' => 'LOCAL', 'name' => 'Local supplier', 'is_supplier' => true, 'is_customer' => true]);
        $this->rejected(fn () => $service->saveOffer(['supplier_id' => $foreignParty->id, 'package_id' => $package->id, 'unit_price' => '1', 'expected_currency' => 'MYR']), 404);
        $this->rejected(fn () => $service->saveOffer(['supplier_id' => $party->id, 'package_id' => $foreignPackage->id, 'unit_price' => '1', 'expected_currency' => 'MYR']), 404);
        $this->rejected(fn () => $service->snapshot(['party_id' => $party->id, 'package_id' => $package->id, 'offer_id' => $foreignOffer->id, 'quantity' => '1']), 404);
        $this->rejected(fn () => $service->savePackage(['id' => $package->id, 'version' => $package->version, 'code' => 'BAG', 'name' => 'Changed', 'conversion' => '3', 'sales_price' => null]), 409);
        $this->rejected(fn () => $service->savePackage(['id' => $package->id, 'version' => $package->version, 'code' => 'BAG', 'name' => 'Changed', 'product_id' => $foreign->id, 'sales_price' => null]), 409);
        $branch = (new AdministrationService)->saveBranch(['name' => 'Second']);
        $this->rejected(fn () => $service->saveLocation(['branch_id' => $branch->id, 'code' => 'S', 'name' => 'Wrong context']), 404);
        $role = (new AdministrationService)->saveRole(['name' => 'Reader', 'permissions' => ['catalogue.read']]);
        $member = Membership::create(['company_id' => $one['company']->id, 'user_id' => $two['owner']->id, 'role_id' => $role->id]);
        $member->branches()->attach($one['branch']->id);
        $this->acting($one, $two['owner']);
        $this->assertSame(1, $service->listing('products')->total());
        $this->rejected(fn () => $service->saveProduct(['sku' => 'NO', 'name' => 'Denied', 'base_uom' => 'kg']), 403);
        foreach (['saveParty', 'saveProduct', 'savePackage', 'saveOffer', 'saveTaxRate', 'saveLocation'] as $method) {
            $this->rejected(fn () => $service->$method([]), 403);
        }
        $this->rejected(fn () => $service->setActive('products', []), 403);
        $this->rejected(fn () => (new AdministrationService)->saveCompany(['currency' => 'x']), 403);
        $this->acting($one);
        \Request::instance()->headers->remove('X-Workspace-Context');
        $this->rejected(fn () => $service->saveProduct(['sku' => 'NO', 'name' => 'Denied', 'base_uom' => 'kg']), 409);
        foreach (['saveParty', 'saveProduct', 'savePackage', 'saveOffer', 'saveTaxRate', 'saveLocation', 'snapshot'] as $method) {
            $this->rejected(fn () => $service->$method([]), 409);
        }
        $this->rejected(fn () => $service->setActive('products', []), 409);
        $this->rejected(fn () => (new AdministrationService)->saveCompany(['currency' => 'x']), 409);
        $this->assertFalse(Product::where('sku', 'NO')->exists());
    }

    public function testLocationsFollowSelectedAndGrantedBranches(): void
    {
        $one = $this->company();
        $two = $this->company();
        $this->acting($one);
        $branch = (new AdministrationService)->saveBranch(['name' => 'Second']);
        $service = new CatalogueService;
        $first = $service->saveLocation(['branch_id' => $one['branch']->id, 'code' => 'MAIN', 'name' => 'First']);
        Workspace::switchTo($one['company']->id, $branch->id);
        \Request::instance()->headers->set('X-Workspace-Context', Workspace::token());
        $second = $service->saveLocation(['branch_id' => $branch->id, 'code' => 'MAIN', 'name' => 'Second']);
        $this->assertSame(1, $service->listing('locations')->total());
        $this->rejected(fn () => $service->find('locations', $first->id), 404);
        Workspace::switchTo($one['company']->id);
        $this->assertSame(2, $service->listing('locations')->total());
        $role = \Vmod\Core\Models\Role::create(['company_id' => $one['company']->id, 'name' => 'Reader', 'permissions' => ['catalogue.read']]);
        $member = Membership::create(['company_id' => $one['company']->id, 'user_id' => $two['owner']->id, 'role_id' => $role->id]);
        $member->branches()->attach($one['branch']->id);
        $this->acting($one, $two['owner']);
        Workspace::switchTo($one['company']->id);
        $this->assertSame(1, $service->listing('locations')->total());
        $this->rejected(fn () => $service->find('locations', $second->id), 404);
    }

    public function testVersionValidationAndAuditFailureRollback(): void
    {
        $one = $this->company();
        $this->acting($one);
        $service = new CatalogueService;
        $product = $this->product($service);
        $this->rejected(fn () => $service->savePackage(['product_id' => $product->id, 'expected_base_uom' => 'g', 'code' => 'STALE', 'name' => 'Stale base unit', 'conversion' => '1']), 409);
        $data = ['id' => $product->id, 'version' => $product->version, 'sku' => 'RICE', 'name' => 'Updated', 'base_uom' => 'kg'];
        $service->saveProduct($data);
        $count = Audit::count();
        $this->rejected(fn () => $service->saveProduct($data), 409);
        $this->assertSame($count, Audit::count());
        $this->rejected(fn () => $service->saveProduct(['sku' => 'RICE', 'name' => 'Duplicate', 'base_uom' => 'kg']));
        $this->rejected(fn () => $service->savePackage(['product_id' => $product->id, 'expected_base_uom' => 'kg', 'code' => 'BAD', 'name' => 'Bad', 'conversion' => ['1']]));
        $this->rejected(fn () => $this->package($service, $product, '1.00001'));
        $this->assertSame(0, ProductPackage::where('company_id', $one['company']->id)->count());
        $listener = function () {
            throw new \RuntimeException('Synthetic audit failure');
        };
        \Event::listen('eloquent.creating: ' . Audit::class, $listener);
        try {
            $this->package($service, $product, '12');
            $this->fail('Audit failure must roll back the price and currency lock.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Synthetic audit failure', $exception->getMessage());
        } finally {
            \Event::forget('eloquent.creating: ' . Audit::class);
        }
        $this->assertSame(0, ProductPackage::where('company_id', $one['company']->id)->count());
        $this->assertNull($one['company']->fresh()->currency_locked_at);
    }
}
