<?php namespace Vmod\Erp\Models;

class Product extends \Model
{
    public $table = 'vmod_erp_products';
    protected $fillable = ['sku', 'name', 'description', 'base_uom'];
    protected $casts = ['is_active' => 'boolean'];
}
