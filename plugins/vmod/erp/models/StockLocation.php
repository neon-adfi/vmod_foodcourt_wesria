<?php namespace Vmod\Erp\Models;

class StockLocation extends \Model
{
    public $table = 'vmod_erp_locations';
    protected $fillable = ['code', 'name'];
    protected $casts = ['is_active' => 'boolean'];
    public $belongsTo = ['branch' => [\Vmod\Core\Models\Branch::class]];
}
