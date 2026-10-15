<?php namespace Vmod\Core;

use Config;
use Event;
use Route;
use System\Classes\PluginBase;
use Vmod\Core\Models\User;

class Plugin extends PluginBase
{
    public $require = ['RainLab.User'];

    public function pluginDetails()
    {
        return ['name' => 'Core', 'description' => 'Frontend identity and workspaces', 'author' => 'Vmod', 'icon' => 'icon-building'];
    }

    public function register()
    {
        Config::set('auth.providers.users.model', User::class);
        Config::set('rainlab.user::allow_registration', false);
        // The owned bind-mounted file store failed concurrent lock acceptance; use Laravel's native MySQL locks.
        Config::set('cache.stores.vmod_session', [
            'driver' => 'database', 'connection' => 'mysql', 'table' => 'cache',
            'lock_connection' => 'mysql', 'lock_table' => 'vmod_core_session_locks',
        ]);
        Config::set('session.block_store', 'vmod_session');
        $this->registerConsoleCommand('vmod.bootstrap', \Vmod\Core\Console\Bootstrap::class);
    }

    public function boot()
    {
        // Enforce the internal-onboarding contract even if an old setting was saved.
        \RainLab\User\Models\Setting::extend(function ($model) {
            $model->bindEvent('model.afterFetch', function () use ($model) {
                $model->allow_registration = false;
            });
        });
        Event::listen('rainlab.user.passwordReset', function ($action, $user) {
            \Auth::logoutOtherDevicesForcefully($user);
        });
        Event::listen('cms.route', function () {
            foreach (Route::getRoutes() as $route) {
                if ($route->getActionName() === 'Cms\Classes\CmsController@run') {
                    // October removes this middleware from web; its installed AJAX path needs native CSRF restored.
                    $route->middleware(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class)->block(60, 10);
                }
            }
        });
    }

    public function registerComponents()
    {
        return [
            \Vmod\Core\Components\Identity::class => 'identity',
            \Vmod\Core\Components\Workspaces::class => 'workspaces',
        ];
    }

    public function registerMailTemplates()
    {
        return ['vmod.core::mail.invitation'];
    }
}
