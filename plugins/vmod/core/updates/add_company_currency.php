<?php namespace Vmod\Core\Updates;

use Schema;
use October\Rain\Database\Updates\Migration;

class AddCompanyCurrency extends Migration
{
    public function up()
    {
        Schema::table('vmod_core_companies', function ($table) {
            $table->string('currency', 3)->default('MYR');
            $table->timestamp('currency_locked_at')->nullable();
        });
    }

    public function down()
    {
        Schema::table('vmod_core_companies', function ($table) {
            $table->dropColumn(['currency', 'currency_locked_at']);
        });
    }
}
