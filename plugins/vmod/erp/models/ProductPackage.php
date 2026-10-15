<?php namespace Vmod\Erp\Models;

class ProductPackage extends \Model
{
    public $table = 'vmod_erp_packages';
    protected $fillable = ['code', 'name', 'conversion', 'sales_price'];
    protected $casts = ['is_active' => 'boolean'];
    public $belongsTo = ['product' => [Product::class]];
}
