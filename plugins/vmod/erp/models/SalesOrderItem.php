<?php namespace Vmod\Erp\Models;

class SalesOrderItem extends \Model
{
    public $table = 'vmod_erp_sales_order_items';
    protected $guarded = ['id'];
    public $timestamps = false;
    protected $jsonable = ['snapshot'];
}
