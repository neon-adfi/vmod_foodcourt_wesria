<?php namespace Vmod\Erp\Updates;

use Schema;
use October\Rain\Database\Updates\Migration;

class CreateSalesReservationTables extends Migration
{
    public function up()
    {
        Schema::create('vmod_erp_sales_orders', function ($t) {
            $this->document($t);
            $t->unsignedBigInteger('customer_id');
            $t->unsignedBigInteger('revision_id')->nullable();
            $t->timestamp('first_dispatch_at')->nullable();
            $t->text('close_reason')->nullable();
            $t->foreign(['company_id', 'customer_id'])->references(['company_id', 'id'])->on('vmod_erp_parties');
        });
        Schema::create('vmod_erp_sales_order_revisions', function ($t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('sales_order_id');
            $t->unsignedInteger('number');
            $t->date('document_date');
            $t->text('notes')->nullable();
            $t->char('currency', 3);
            $t->string('price_mode', 10);
            $t->json('customer_snapshot');
            foreach (['net', 'tax', 'gross'] as $amount) {
                $t->decimal($amount, 19, 2);
            }
            $t->unsignedBigInteger('actor_user_id');
            $t->timestamp('created_at');
            $t->timestamp('approved_at')->nullable();
            $t->unique(['sales_order_id', 'number'], 'erp_so_revision_number');
            $t->index(['sales_order_id', 'id']);
            $t->foreign('sales_order_id')->references('id')->on('vmod_erp_sales_orders');
            $t->foreign('actor_user_id')->references('id')->on('users');
        });
        Schema::table('vmod_erp_sales_orders', function ($t) {
            $t->foreign('revision_id')->references('id')->on('vmod_erp_sales_order_revisions');
        });
        Schema::create('vmod_erp_sales_order_items', function ($t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('revision_id');
            $t->unsignedInteger('position');
            $t->unsignedBigInteger('product_id');
            $t->unsignedBigInteger('location_id');
            $t->decimal('quantity', 19, 4);
            $t->decimal('base_quantity', 19, 4);
            $t->json('snapshot');
            foreach (['net', 'tax', 'gross'] as $amount) {
                $t->decimal($amount, 19, 2);
            }
            $t->unique(['revision_id', 'position'], 'erp_so_item_position');
            $t->foreign('revision_id')->references('id')->on('vmod_erp_sales_order_revisions');
            $t->foreign('product_id')->references('id')->on('vmod_erp_products');
            $t->foreign('location_id')->references('id')->on('vmod_erp_locations');
        });
        Schema::create('vmod_erp_delivery_orders', function ($t) {
            $this->document($t);
            $t->unsignedBigInteger('sales_order_id');
            $t->unsignedBigInteger('revision_id');
            $t->unsignedBigInteger('root_id')->nullable();
            $t->unsignedBigInteger('replaces_id')->nullable()->unique();
            $t->date('document_date');
            $t->text('notes')->nullable();
            $t->text('reason')->nullable();
            $t->timestamp('dispatched_at')->nullable();
            $t->foreign(['company_id', 'sales_order_id'])->references(['company_id', 'id'])->on('vmod_erp_sales_orders');
            $t->foreign('revision_id')->references('id')->on('vmod_erp_sales_order_revisions');
            $t->foreign('root_id')->references('id')->on('vmod_erp_delivery_orders');
            $t->foreign('replaces_id')->references('id')->on('vmod_erp_delivery_orders');
        });
        Schema::create('vmod_erp_delivery_order_items', function ($t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('delivery_order_id');
            $t->unsignedBigInteger('sales_order_item_id');
            $t->unsignedBigInteger('location_id');
            $t->decimal('quantity', 19, 4);
            $t->decimal('base_quantity', 19, 4);
            $t->foreign('delivery_order_id')->references('id')->on('vmod_erp_delivery_orders');
            $t->foreign('sales_order_item_id')->references('id')->on('vmod_erp_sales_order_items');
            $t->foreign('location_id')->references('id')->on('vmod_erp_locations');
        });
        Schema::create('vmod_erp_stock_reservations', function ($t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('company_id');
            $t->unsignedBigInteger('branch_id');
            $t->unsignedBigInteger('sales_order_id');
            $t->unsignedBigInteger('sales_order_item_id')->unique();
            $t->unsignedBigInteger('product_id');
            $t->unsignedBigInteger('location_id');
            $t->decimal('approved', 19, 4);
            $t->decimal('dispatched', 19, 4)->default(0);
            $t->decimal('released', 19, 4)->default(0);
            $t->timestamps();
            $t->index(['company_id', 'branch_id', 'location_id', 'product_id'], 'erp_reservation_stock_key');
            $t->foreign(['company_id', 'sales_order_id'])->references(['company_id', 'id'])->on('vmod_erp_sales_orders');
            $t->foreign('sales_order_item_id')->references('id')->on('vmod_erp_sales_order_items');
            $t->foreign(['company_id', 'product_id'])->references(['company_id', 'id'])->on('vmod_erp_products');
            $t->foreign(['company_id', 'location_id'])->references(['company_id', 'id'])->on('vmod_erp_locations');
            $t->foreign('branch_id')->references('id')->on('vmod_core_branches');
        });
        Schema::table('vmod_erp_stock_balances', function ($t) {
            $t->decimal('reserved', 19, 4)->default(0);
        });
        Schema::table('vmod_erp_stock_movements', function ($t) {
            $t->unsignedBigInteger('delivery_item_id')->nullable();
            $t->foreign('delivery_item_id')->references('id')->on('vmod_erp_delivery_order_items');
            $t->unique(['delivery_item_id', 'action'], 'erp_delivery_posting_identity');
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

    public function down()
    {
        Schema::table('vmod_erp_stock_movements', function ($t) {
            $t->dropForeign(['delivery_item_id']);
            $t->dropUnique('erp_delivery_posting_identity');
            $t->dropColumn('delivery_item_id');
        });
        Schema::table('vmod_erp_stock_balances', fn ($t) => $t->dropColumn('reserved'));
        foreach (['stock_reservations', 'delivery_order_items', 'delivery_orders', 'sales_order_items'] as $table) {
            Schema::dropIfExists('vmod_erp_' . $table);
        }
        Schema::table('vmod_erp_sales_orders', fn ($t) => $t->dropForeign(['revision_id']));
        Schema::dropIfExists('vmod_erp_sales_order_revisions');
        Schema::dropIfExists('vmod_erp_sales_orders');
    }
}
