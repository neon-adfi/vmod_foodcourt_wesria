<?php namespace Vmod\Tests;

use DB;
use Illuminate\Contracts\Console\Kernel;
use Symfony\Component\Console\Tester\CommandTester;
use Vmod\Core\Console\Bootstrap;
use Vmod\Core\Classes\Permissions;
use Vmod\Core\Models\Company;
use Vmod\Core\Models\Membership;

class FoundationInstallTest extends ApplicationTestCase
{
    protected $autoMigrate = false;

    public function testInstalledContractAndInteractiveGuardedBootstrap(): void
    {
        $kernel = $this->app->make(Kernel::class);
        $this->assertSame(0, $kernel->call('october:migrate', ['--no-interaction' => true]));
        $this->assertSame('8.2.34', PHP_VERSION);
        $this->assertSame('v3.7.2', \Composer\InstalledVersions::getPrettyVersion('rainlab/user-plugin'));
        $this->assertEqualsCanonicalizing(['October.Demo', 'RainLab.User', 'Vmod.Core', 'Vmod.Erp',
            'Vmod.Circularity', 'Vmod.CircularityUi'], array_keys(\System\Classes\PluginManager::instance()->getPlugins()));
        foreach (\Composer\InstalledVersions::getInstalledPackages() as $package) {
            $this->assertDoesNotMatchRegularExpression('/^(gaia|vector|renatio)\\/|vmod\\/(print|ubs|emailparser)/i', $package);
        }
        foreach (['RainLab.User', 'Vmod.Core', 'Vmod.Erp'] as $plugin) {
            $this->assertNotNull(\System\Classes\PluginManager::instance()->findByIdentifier($plugin));
        }
        $this->assertTrue(\Schema::hasTable('vmod_erp_invoice_references'));
        $this->assertTrue(\Schema::hasColumn('vmod_erp_purchase_order_revisions', 'issuer_snapshot'));
        foreach (DB::select('SHOW TABLES') as $row) {
            $this->assertDoesNotMatchRegularExpression('/^(gaia|vector|foodcourt|fcm_|renatio|vmod_ubs|vmod_print|vmod_emailparser)/i', array_values((array) $row)[0]);
        }
        $n = bin2hex(random_bytes(6));
        $secret = bin2hex(random_bytes(20)) . '4!';
        $command = new Bootstrap;
        $command->setLaravel($this->app);
        $tester = new CommandTester($command);
        $tester->setInputs(['install-' . $n, 'Fresh owner company', 'Main', 'Owner', $n . '@example.invalid', $secret, $secret]);
        DB::beginTransaction();
        try {
            $this->assertSame(0, $tester->execute([], ['interactive' => true]));
            $this->assertStringNotContainsString($secret, $tester->getDisplay());
            $company = Company::where('code', 'install-' . $n)->sole();
            $member = Membership::where('company_id', $company->id)->sole();
            $this->assertEqualsCanonicalizing(Permissions::all(), $member->role->permissions);
            $this->assertCount(1, $member->branchIds());
            $this->assertSame($n . '@example.invalid', $member->user->email);
        } finally {
            DB::rollBack();
        }
        $this->assertFalse(Company::where('code', 'install-' . $n)->exists());
    }
}
