<?php namespace Vmod\CircularityUi;

class Plugin extends \System\Classes\PluginBase
{
    public $require = ['Vmod.Core'];

    public function pluginDetails()
    {
        return ['name' => 'Circularity UI', 'description' => 'Workspace bridge to the circularity API', 'author' => 'Vmod', 'icon' => 'icon-recycle'];
    }

    public function register()
    {
        \Event::listen('vmod.core.extendPermissions', fn () => ['circularity.read', 'circularity.write']);
    }
}
