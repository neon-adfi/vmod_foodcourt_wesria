<?php namespace Vmod\Erp\Updates;

use Schema;
use October\Rain\Database\Updates\Migration;

class CreatePurchasingStockTables extends Migration
{
    public function up()
    {
        Schema::create('vmod_erp_operations', function ($t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('company_id');
            $t->string('action', 40);
            $t->uuid('operation_key');
            $t->char('fingerprint', 64);
            $t->unsignedBigInteger('result_id')->nullable();
            $t->timestamp('created_at');
            $t->unique(['company_id', 'action', 'operation_key'], 'erp_operation_identity');
            $t->foreign('company_id')->references('id')->on('vmod_core_companies');
        });
        Schema::create('vmod_erp_document_counters', function ($t) {
            $t->unsignedBigInteger('company_id');
            $t->string('type', 10);
            $t->unsignedBigInteger('last_number')->default(0);
            $t->primary(['company_id', 'type']);
            $t->foreign('company_id')->references('id')->on('vmod_core_companies');
        });
        Schema::create('vmod_erp_purchase_orders', function ($t) {
            $this->document($t);
            $t->unsignedBigInteger('supplier_id');
            $t->unsignedBigInteger('revision_id')->nullable();
            $t->timestamp('first_received_at')->nullable();
            $t->text('close_reason')->nullable();
            $t->foreign(['company_id', 'supplier_id'])->references(['company_id', 'id'])->on('vmod_erp_parties');
        });
        Schema::create('vmod_erp_purchase_order_revisions', function ($t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('purchase_order_id');
            $t->unsignedInteger('number');
            $t->date('document_date');
            $t->text('notes')->nullable();
            $t->char('currency', 3);
            $t->string('price_mode', 10);
            $t->json('supplier_snapshot');
            foreach (['net', 'tax', 'gross'] as $amount) {
                $t->decimal($amount, 19, 2);
            }
            $t->unsignedBigInteger('actor_user_id');
            $t->timestamp('created_at');
            $t->timestamp('approved_at')->nullable();
            $t->unique(['purchase_order_id', 'number'], 'erp_po_revision_number');
            $t->index(['purchase_order_id', 'id']);
            $t->foreign('purchase_order_id')->references('id')->on('vmod_erp_purchase_orders');
            $t->foreign('actor_user_id')->references('id')->on('users');
        });
        Schema::table('vmod_erp_purchase_orders', function ($t) {
            $t->foreign('revision_id')->references('id')->on('vmod_erp_purchase_order_revisions');
        });
        Schema::create('vmod_erp_purchase_order_items', function ($t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('revision_id');
            $t->unsignedInteger('position');
            $t->unsignedBigInteger('product_id');
            $t->decimal('quantity', 19, 4);
            $t->decimal('base_quantity', 19, 4);
            $t->json('snapshot');
            foreach (['net', 'tax', 'gross'] as $amount) {
                $t->decimal($amount, 19, 2);
            }
            $t->unique(['revision_id', 'position'], 'erp_po_item_position');
            $t->foreign('revision_id')->references('id')->on('vmod_erp_purchase_order_revisions');
            $t->foreign('product_id')->references('id')->on('vmod_erp_products');
        });
        Schema::create('vmod_erp_goods_receipts', function ($t) {
            $this->document($t);
            $t->unsignedBigInteger('purchase_order_id');
            $t->unsignedBigInteger('revision_id');
            $t->unsignedBigInteger('root_id')->nullable();
            $t->unsignedBigInteger('replaces_id')->nullable()->unique();
            $t->string('supplier_reference', 160);
            $t->date('document_date');
            $t->text('reason')->nullable();
            $t->timestamp('confirmed_at')->nullable();
            $t->foreign(['company_id', 'purchase_order_id'])->references(['company_id', 'id'])->on('vmod_erp_purchase_orders');
            $t->foreign('revision_id')->references('id')->on('vmod_erp_purchase_order_revisions');
            $t->foreign('root_id')->references('id')->on('vmod_erp_goods_receipts');
            $t->foreign('replaces_id')->references('id')->on('vmod_erp_goods_receipts');
        });
        Schema::create('vmod_erp_goods_receipt_items', function ($t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('goods_receipt_id');
            $t->unsignedBigInteger('purchase_order_item_id');
            $t->unsignedBigInteger('location_id');
            $t->decimal('quantity', 19, 4);
            $t->decimal('base_quantity', 19, 4);
            $t->foreign('goods_receipt_id')->references('id')->on('vmod_erp_goods_receipts');
            $t->foreign('purchase_order_item_id')->references('id')->on('vmod_erp_purchase_order_items');
            $t->foreign('location_id')->references('id')->on('vmod_erp_locations');
        });
        Schema::create('vmod_erp_delivery_references', function ($t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('company_id');
            $t->unsignedBigInteger('supplier_id');
            $t->string('normalized_reference', 160)->collation('utf8mb4_bin');
            $t->unsignedBigInteger('root_id');
            $t->unique(['company_id', 'supplier_id', 'normalized_reference'], 'erp_supplier_delivery_reference');
            $t->foreign(['company_id', 'supplier_id'])->references(['company_id', 'id'])->on('vmod_erp_parties');
            $t->foreign(['company_id', 'root_id'])->references(['company_id', 'id'])->on('vmod_erp_goods_receipts');
        });
        Schema::create('vmod_erp_stock_balances', function ($t) {
            $t->bigIncrements('id');
            $this->stockKey($t);
            $t->decimal('on_hand', 19, 4)->default(0);
            $t->unique(['company_id', 'branch_id', 'location_id', 'product_id'], 'erp_stock_balance_key');
        });
        Schema::create('vmod_erp_stock_movements', function ($t) {
            $t->bigIncrements('id');
            $this->stockKey($t);
            $t->unsignedBigInteger('operation_id');
            $t->unsignedBigInteger('receipt_item_id')->nullable();
            $t->string('action', 20);
            $t->decimal('quantity', 19, 4);
            $t->string('base_uom', 30);
            $t->text('reason')->nullable();
            $t->string('reference', 255)->nullable();
            $t->unsignedBigInteger('actor_user_id');
            $t->timestamp('created_at');
            $t->unique(['receipt_item_id', 'action'], 'erp_receipt_posting_identity');
            $t->unique(['operation_id', 'action', 'location_id', 'product_id', 'receipt_item_id'], 'erp_operation_posting_identity');
            $t->index(['company_id', 'product_id']);
            $t->foreign('operation_id')->references('id')->on('vmod_erp_operations');
            $t->foreign('receipt_item_id')->references('id')->on('vmod_erp_goods_receipt_items');
            $t->foreign('actor_user_id')->references('id')->on('users');
        });
    }

    private function document($t): void
    {
        $t->bigIncrements('id');
        $t->unsignedBigInteger('company_id');
        $t->unsignedBigInteger('branch_id');
        $t->string('number', 40);
        $t->string('state', 30)->default('draft');
        $t->unsignedInteger('version')->default(1);
        $t->timestamps();
        $t->unique(['company_id', 'number']);
        $t->index(['company_id', 'id']);
        $t->foreign('company_id')->references('id')->on('vmod_core_companies');
        $t->foreign('branch_id')->references('id')->on('vmod_core_branches');
    }

    private function stockKey($t): void
    {
        $t->unsignedBigInteger('company_id');
        $t->unsignedBigInteger('branch_id');
        $t->unsignedBigInteger('location_id');
        $t->unsignedBigInteger('product_id');
        $t->foreign(['company_id', 'product_id'])->references(['company_id', 'id'])->on('vmod_erp_products');
        $t->foreign(['company_id', 'location_id'])->references(['company_id', 'id'])->on('vmod_erp_locations');
        $t->foreign('branch_id')->references('id')->on('vmod_core_branches');
    }

    public function down()
    {
        foreach (['stock_movements', 'stock_balances', 'delivery_references', 'goods_receipt_items', 'goods_receipts', 'purchase_order_items'] as $table) {
            Schema::dropIfExists('vmod_erp_' . $table);
        }
        Schema::table('vmod_erp_purchase_orders', fn ($t) => $t->dropForeign(['revision_id']));
        foreach (['purchase_order_revisions', 'purchase_orders', 'document_counters', 'operations'] as $table) {
            Schema::dropIfExists('vmod_erp_' . $table);
        }
    }
}
