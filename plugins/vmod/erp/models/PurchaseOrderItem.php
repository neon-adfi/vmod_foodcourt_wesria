<?php namespace Vmod\Erp\Models;

class PurchaseOrderItem extends \Model
{
    public $table = 'vmod_erp_purchase_order_items';
    protected $guarded = ['id'];
    public $timestamps = false;
    protected $jsonable = ['snapshot'];
}
