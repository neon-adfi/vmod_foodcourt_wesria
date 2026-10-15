<?php namespace Vmod\Erp\Models;

class PurchaseOrder extends \Model
{
    public $table = 'vmod_erp_purchase_orders';
    protected $guarded = ['id'];
    public $belongsTo = ['revision' => [PurchaseOrderRevision::class, 'key' => 'revision_id']];
    public $hasMany = ['revisions' => [PurchaseOrderRevision::class, 'order' => 'number desc']];
}
