<?php namespace Vmod\Erp\Updates;

use Schema;
use October\Rain\Database\Updates\Migration;

class CreateCatalogueTables extends Migration
{
    public function up()
    {
        foreach (['parties', 'products', 'packages', 'offers', 'tax_rates', 'locations'] as $resource) {
            Schema::create('vmod_erp_' . $resource, function ($table) use ($resource) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('company_id');
                $table->boolean('is_active')->default(true);
                $table->unsignedInteger('version')->default(1);
                $table->timestamps();
                $table->foreign('company_id')->references('id')->on('vmod_core_companies');
                $table->index(['company_id', 'id']);
                if ($resource === 'parties') {
                    $table->string('code', 80);
                    $table->string('name');
                    $table->boolean('is_customer')->default(false);
                    $table->boolean('is_supplier')->default(false);
                    $table->string('email')->nullable();
                    $table->string('phone', 80)->nullable();
                    $table->text('address')->nullable();
                    $table->unique(['company_id', 'code'], 'erp_party_code');
                } elseif ($resource === 'products') {
                    $table->string('sku', 80);
                    $table->string('name');
                    $table->text('description')->nullable();
                    $table->string('base_uom', 30);
                    $table->unique(['company_id', 'sku'], 'erp_product_sku');
                } elseif ($resource === 'packages') {
                    $table->unsignedBigInteger('product_id');
                    $table->string('code', 80);
                    $table->string('name');
                    $table->decimal('conversion', 19, 4);
                    $table->decimal('sales_price', 19, 4)->nullable();
                    $table->foreign(['company_id', 'product_id'])->references(['company_id', 'id'])->on('vmod_erp_products');
                    $table->unique(['company_id', 'product_id', 'code'], 'erp_package_code');
                } elseif ($resource === 'offers') {
                    $table->unsignedBigInteger('supplier_id');
                    $table->unsignedBigInteger('package_id');
                    $table->decimal('unit_price', 19, 4);
                    $table->foreign(['company_id', 'supplier_id'])->references(['company_id', 'id'])->on('vmod_erp_parties');
                    $table->foreign(['company_id', 'package_id'])->references(['company_id', 'id'])->on('vmod_erp_packages');
                    $table->unique(['company_id', 'supplier_id', 'package_id'], 'erp_offer_supplier_package');
                } elseif ($resource === 'tax_rates') {
                    $table->string('name', 80);
                    $table->decimal('rate', 7, 4);
                    $table->unique(['company_id', 'name'], 'erp_tax_name');
                } else {
                    $table->unsignedBigInteger('branch_id');
                    $table->string('code', 80);
                    $table->string('name');
                    $table->foreign('branch_id')->references('id')->on('vmod_core_branches');
                    $table->unique(['company_id', 'branch_id', 'code'], 'erp_location_code');
                }
            });
        }
    }

    public function down()
    {
        foreach (['locations', 'tax_rates', 'offers', 'packages', 'products', 'parties'] as $resource) {
            Schema::dropIfExists('vmod_erp_' . $resource);
        }
    }
}
