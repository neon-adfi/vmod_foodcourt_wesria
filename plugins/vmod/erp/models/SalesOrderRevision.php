<?php namespace Vmod\Erp\Models;

class SalesOrderRevision extends \Model
{
    public $table = 'vmod_erp_sales_order_revisions';
    protected $guarded = ['id'];
    public $timestamps = false;
    protected $jsonable = ['customer_snapshot', 'issuer_snapshot'];
    public $hasMany = ['items' => [SalesOrderItem::class, 'key' => 'revision_id', 'order' => 'position']];
}
