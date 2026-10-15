<?php namespace Vmod\Erp\Models;

class InvoiceReferenceLink extends \Model
{
    public $table = 'vmod_erp_invoice_reference_links';
    public $timestamps = false;
    protected $guarded = ['id'];
    public $belongsTo = [
        'purchase_order' => [PurchaseOrder::class], 'sales_order' => [SalesOrder::class],
        'goods_receipt' => [GoodsReceipt::class], 'delivery_order' => [DeliveryOrder::class],
    ];

    public function identity(): array
    {
        foreach (['po' => 'purchase_order_id', 'so' => 'sales_order_id', 'grn' => 'goods_receipt_id', 'do' => 'delivery_order_id'] as $kind => $column) {
            if ($this->$column) {
                return ['kind' => $kind, 'id' => (int) $this->$column, 'revision_id' => (int) ($this->purchase_revision_id ?: $this->sales_revision_id)];
            }
        }
        throw new \LogicException('Invoice link requires a document.');
    }
}
