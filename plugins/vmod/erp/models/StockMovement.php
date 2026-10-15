<?php namespace Vmod\Erp\Models;

class StockMovement extends \Model
{
    public $table = 'vmod_erp_stock_movements';
    protected $guarded = ['id'];
    public $timestamps = false;
    public $belongsTo = ['product' => [Product::class], 'location' => [StockLocation::class]];
}
