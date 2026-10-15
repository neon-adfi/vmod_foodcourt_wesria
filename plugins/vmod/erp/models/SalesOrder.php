<?php namespace Vmod\Erp\Models;

class SalesOrder extends \Model
{
    public $table = 'vmod_erp_sales_orders';
    protected $guarded = ['id'];
    public $belongsTo = ['revision' => [SalesOrderRevision::class, 'key' => 'revision_id']];
    public $hasMany = ['revisions' => [SalesOrderRevision::class, 'order' => 'number desc']];
}
