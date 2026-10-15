<?php namespace Vmod\Tests;

use Illuminate\Auth\AuthManager;
use Illuminate\Contracts\Console\Kernel;
use Vmod\Core\Models\User;
use System\Classes\PluginManager;

class FoundationBootstrapTest extends ApplicationTestCase
{
    protected $autoMigrate = false;

    public function testNativeApplicationBootAndMysqlRollback(): void
    {
        $this->assertSame('8.2.34', PHP_VERSION);
        $this->assertSame(base_path('plugins'), $this->app->pluginsPath());
        $this->assertInstanceOf(AuthManager::class, $this->app['auth']);
        $this->assertSame(User::class, config('auth.providers.users.model'));
        $this->assertTrue((bool) config('system.enable_csrf_protection'));
        $this->assertNotNull(PluginManager::instance()->findByIdentifier('RainLab.User'));

        $db = $this->app['db']->connection();
        $this->assertSame('mysql', $db->getDriverName());
        $this->assertSame('vmod_wesria_test', $db->selectOne('select database() as name')->name);
        $this->assertSame(0, $this->app->make(Kernel::class)->call('october:migrate', ['--no-interaction' => true]));
        $this->assertTrue($db->getSchemaBuilder()->hasTable('users'));

        $email = 'foundation-' . bin2hex(random_bytes(8)) . '@example.invalid';
        $db->beginTransaction();
        try {
            $user = new User;
            $user->email = $email;
            $user->first_name = 'Synthetic';
            $user->password = $user->password_confirmation = bin2hex(random_bytes(16));
            $user->save();
            $this->assertSame($user->id, $this->app['auth']->guard('web')->getProvider()->retrieveById($user->id)->id);
        } finally {
            $db->rollBack();
        }
        $this->assertFalse($db->table('users')->where('email', $email)->exists());
        $this->assertSame(0, $db->transactionLevel());
    }
}
