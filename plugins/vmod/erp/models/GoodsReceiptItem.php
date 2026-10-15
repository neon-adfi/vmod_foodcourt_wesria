<?php namespace Vmod\Erp\Models;

class GoodsReceiptItem extends \Model
{
    public $table = 'vmod_erp_goods_receipt_items';
    protected $guarded = ['id'];
    public $timestamps = false;
    public $belongsTo = ['purchase_item' => [PurchaseOrderItem::class, 'key' => 'purchase_order_item_id'], 'location' => [StockLocation::class]];
}
