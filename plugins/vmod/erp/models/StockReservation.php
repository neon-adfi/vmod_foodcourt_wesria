<?php namespace Vmod\Erp\Models;

class StockReservation extends \Model
{
    public $table = 'vmod_erp_stock_reservations';
    protected $guarded = ['id'];
    public $belongsTo = ['sales_item' => [SalesOrderItem::class, 'key' => 'sales_order_item_id']];
}
