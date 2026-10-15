<?php namespace Vmod\Core\Updates;

use Schema;
use October\Rain\Database\Updates\Migration;

class CreateSessionLocks extends Migration
{
    public function up()
    {
        // Standard Laravel database-cache lock schema; no custom lock implementation.
        Schema::create('vmod_core_session_locks', function ($table) {
            $table->string('key')->primary();
            $table->string('owner');
            $table->integer('expiration');
        });
    }

    public function down()
    {
        Schema::dropIfExists('vmod_core_session_locks');
    }
}
