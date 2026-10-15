<?php namespace Vmod\Erp\Updates;

use DB;
use Schema;
use October\Rain\Database\Updates\Migration;

class CreateInvoiceReferences extends Migration
{
    public function up()
    {
        Schema::create('vmod_erp_invoice_references', function ($t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('company_id');
            $t->unsignedBigInteger('branch_id');
            $t->unsignedBigInteger('party_id');
            $t->string('direction', 10);
            $t->string('number', 160);
            $t->string('active_number', 160)->nullable()->collation('utf8mb4_bin');
            $t->date('document_date');
            $t->decimal('amount', 19, 2);
            $t->char('currency', 3);
            $t->string('state', 12)->default('active');
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
            $t->unique(['company_id', 'direction', 'party_id', 'active_number'], 'erp_invoice_active_number');
            $t->index(['company_id', 'branch_id', 'state'], 'erp_invoice_scope');
            $t->foreign('company_id')->references('id')->on('vmod_core_companies');
            $t->foreign('branch_id')->references('id')->on('vmod_core_branches');
            $t->foreign(['company_id', 'party_id'])->references(['company_id', 'id'])->on('vmod_erp_parties');
        });
        Schema::create('vmod_erp_invoice_reference_links', function ($t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('invoice_reference_id');
            $t->foreign('invoice_reference_id')->references('id')->on('vmod_erp_invoice_references');
            foreach (['purchase_order' => 'purchase_orders', 'sales_order' => 'sales_orders', 'goods_receipt' => 'goods_receipts', 'delivery_order' => 'delivery_orders'] as $key => $table) {
                $t->unsignedBigInteger($key . '_id')->nullable();
                $t->foreign($key . '_id')->references('id')->on('vmod_erp_' . $table);
                $t->unique(['invoice_reference_id', $key . '_id'], 'erp_ref_link_' . $key);
            }
            $t->unsignedBigInteger('purchase_revision_id')->nullable();
            $t->unsignedBigInteger('sales_revision_id')->nullable();
            $t->foreign('purchase_revision_id')->references('id')->on('vmod_erp_purchase_order_revisions');
            $t->foreign('sales_revision_id')->references('id')->on('vmod_erp_sales_order_revisions');
            $t->string('number', 40);
            $t->date('document_date');
            $t->string('linked_state', 24);
        });
        DB::statement('ALTER TABLE vmod_erp_invoice_reference_links ADD CONSTRAINT erp_ref_one_document CHECK (
            (purchase_order_id IS NOT NULL) + (sales_order_id IS NOT NULL) + (goods_receipt_id IS NOT NULL) + (delivery_order_id IS NOT NULL) = 1)');
        foreach (['purchase', 'sales'] as $type) {
            Schema::table('vmod_erp_' . $type . '_order_revisions', function ($t) {
                $t->json('issuer_snapshot')->nullable();
            });
        }
        Schema::table('vmod_erp_goods_receipt_items', function ($t) {
            $t->string('location_name')->nullable();
        });
    }

    public function down()
    {
        Schema::dropIfExists('vmod_erp_invoice_reference_links');
        Schema::dropIfExists('vmod_erp_invoice_references');
        foreach (['purchase', 'sales'] as $type) {
            Schema::table('vmod_erp_' . $type . '_order_revisions', fn ($t) => $t->dropColumn('issuer_snapshot'));
        }
        Schema::table('vmod_erp_goods_receipt_items', fn ($t) => $t->dropColumn('location_name'));
    }
}
