<?php namespace Vmod\Erp\Models;

class PurchaseOrderRevision extends \Model
{
    public $table = 'vmod_erp_purchase_order_revisions';
    protected $guarded = ['id'];
    public $timestamps = false;
    protected $jsonable = ['supplier_snapshot', 'issuer_snapshot'];
    public $hasMany = ['items' => [PurchaseOrderItem::class, 'key' => 'revision_id', 'order' => 'position']];
}
