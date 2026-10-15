<?php namespace Vmod\Erp\Models;

class InvoiceReference extends \Model
{
    public $table = 'vmod_erp_invoice_references';
    protected $guarded = ['id'];
    public $hasMany = ['links' => [InvoiceReferenceLink::class, 'order' => 'id']];
    public $belongsTo = ['party' => [Party::class]];
}
