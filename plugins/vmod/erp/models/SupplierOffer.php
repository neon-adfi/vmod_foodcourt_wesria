<?php namespace Vmod\Erp\Models;

class SupplierOffer extends \Model
{
    public $table = 'vmod_erp_offers';
    protected $fillable = ['unit_price'];
    protected $casts = ['is_active' => 'boolean'];
    public $belongsTo = ['supplier' => [Party::class, 'key' => 'supplier_id'], 'package' => [ProductPackage::class, 'key' => 'package_id']];
}
