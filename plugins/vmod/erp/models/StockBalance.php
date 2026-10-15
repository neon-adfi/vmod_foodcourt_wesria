<?php namespace Vmod\Erp\Models;

class StockBalance extends \Model
{
    public $table = 'vmod_erp_stock_balances';
    protected $guarded = ['id'];
    public $timestamps = false;
    public $belongsTo = ['product' => [Product::class], 'location' => [StockLocation::class]];
}
