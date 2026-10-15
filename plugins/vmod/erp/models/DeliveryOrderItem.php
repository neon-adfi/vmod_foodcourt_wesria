<?php namespace Vmod\Erp\Models;

class DeliveryOrderItem extends \Model
{
    public $table = 'vmod_erp_delivery_order_items';
    protected $guarded = ['id'];
    public $timestamps = false;
    public $belongsTo = ['sales_item' => [SalesOrderItem::class, 'key' => 'sales_order_item_id'], 'location' => [StockLocation::class]];
}
