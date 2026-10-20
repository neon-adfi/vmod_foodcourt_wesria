<?php namespace Vmod\Circularity\Updates;

use Schema;
use October\Rain\Database\Updates\Migration;

class CreateCaptureTables extends Migration
{
    public function up()
    {
        Schema::create('vmod_circularity_areas', function ($table) {
            $table->bigIncrements('id');
            foreach (['source_namespace', 'tenant_ref', 'branch_ref'] as $field) {
                $table->string($field, 128)->collation('utf8mb4_bin');
            }
            $table->string('name', 120);
            $table->string('kind', 20);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->unique(['source_namespace', 'tenant_ref', 'branch_ref', 'name'], 'circularity_area_name');
        });
        Schema::create('vmod_circularity_entries', function ($table) {
            $table->bigIncrements('id');
            $table->uuid('uuid')->unique('circularity_entry_uuid');
            foreach (['source_namespace', 'tenant_ref', 'branch_ref'] as $field) {
                $table->string($field, 128)->collation('utf8mb4_bin');
            }
            $table->unsignedBigInteger('area_id');
            $table->date('measurement_date');
            $table->string('measurement_type', 30);
            $table->string('mode', 20);
            $table->decimal('quantity', 15, 3);
            $table->string('destination', 30);
            $table->string('reference', 120)->nullable();
            $table->text('notes')->nullable();
            $table->string('created_actor_ref', 128)->collation('utf8mb4_bin');
            $table->string('updated_actor_ref', 128)->collation('utf8mb4_bin');
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->softDeletes();
            $table->foreign('area_id')->references('id')->on('vmod_circularity_areas');
            $table->index(['area_id', 'measurement_date', 'measurement_type', 'deleted_at'], 'circularity_entry_group');
            $table->index(['source_namespace', 'tenant_ref', 'branch_ref', 'measurement_date'], 'circularity_entry_scope');
        });
    }

    public function down()
    {
        // Capture history survives rollback. Disable the UI/routes instead of dropping data.
    }
}
