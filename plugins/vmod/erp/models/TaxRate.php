<?php namespace Vmod\Erp\Models;

class TaxRate extends \Model
{
    public $table = 'vmod_erp_tax_rates';
    protected $fillable = ['name', 'rate'];
    protected $casts = ['is_active' => 'boolean'];
}
