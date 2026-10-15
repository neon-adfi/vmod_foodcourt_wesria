<?php namespace Vmod\Erp\Models;

class GoodsReceipt extends \Model
{
    public $table = 'vmod_erp_goods_receipts';
    protected $guarded = ['id'];
    public $hasMany = ['items' => [GoodsReceiptItem::class]];
    public $belongsTo = ['purchase_order' => [PurchaseOrder::class], 'replacement_of' => [self::class, 'key' => 'replaces_id']];
}
