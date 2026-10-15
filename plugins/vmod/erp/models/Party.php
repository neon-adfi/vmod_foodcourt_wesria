<?php namespace Vmod\Erp\Models;

class Party extends \Model
{
    public $table = 'vmod_erp_parties';
    protected $fillable = ['code', 'name', 'is_customer', 'is_supplier', 'email', 'phone', 'address'];
    protected $casts = ['is_active' => 'boolean', 'is_customer' => 'boolean', 'is_supplier' => 'boolean'];
}
