<?php namespace Vmod\Erp\Models;

class DeliveryOrder extends \Model
{
    public $table = 'vmod_erp_delivery_orders';
    protected $guarded = ['id'];
    public $hasMany = ['items' => [DeliveryOrderItem::class]];
    public $belongsTo = ['sales_order' => [SalesOrder::class], 'replacement_of' => [self::class, 'key' => 'replaces_id']];
}
