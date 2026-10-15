<?php namespace Vmod\Erp\Models;

class StockBalance extends \Model
{
    public $table = 'vmod_erp_stock_balances';
    protected $guarded = ['id'];
    public $timestamps = false;
    public $belongsTo = ['product' => [Product::class], 'location' => [StockLocation::class]];
    public function getAvailableAttribute(): string
    {
        return (string) \Brick\Math\BigDecimal::of($this->on_hand)->minus($this->reserved)->toScale(4);
    }
}
