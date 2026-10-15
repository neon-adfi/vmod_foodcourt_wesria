<?php namespace Vmod\Erp;

class Plugin extends \System\Classes\PluginBase
{
    public $require = ['Vmod.Core'];

    public function pluginDetails()
    {
        return ['name' => 'ERP', 'description' => 'Company catalogue and commercial calculations', 'author' => 'Vmod', 'icon' => 'icon-cubes'];
    }

    public function registerComponents()
    {
        return [\Vmod\Erp\Components\Catalogue::class => 'catalogue'];
    }
}
