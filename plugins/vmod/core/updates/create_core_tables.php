<?php namespace Vmod\Core\Updates;

use Schema;
use October\Rain\Database\Updates\Migration;

class CreateCoreTables extends Migration
{
    public function up()
    {
        Schema::create('vmod_core_companies', function ($table) {
            $table->bigIncrements('id');
            $table->string('code', 80)->unique();
            $table->string('name');
            $table->timestamps();
        });
        Schema::create('vmod_core_branches', function ($table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('company_id');
            $table->string('name');
            $table->timestamps();
            $table->foreign('company_id')->references('id')->on('vmod_core_companies');
            $table->unique(['company_id', 'name']);
        });
        Schema::create('vmod_core_roles', function ($table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('company_id');
            $table->string('name');
            $table->json('permissions');
            $table->timestamps();
            $table->foreign('company_id')->references('id')->on('vmod_core_companies');
            $table->unique(['company_id', 'name']);
        });
        Schema::create('vmod_core_memberships', function ($table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('role_id');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['company_id', 'user_id']);
            $table->foreign('company_id')->references('id')->on('vmod_core_companies');
            $table->foreign('user_id')->references('id')->on('users');
            $table->foreign('role_id')->references('id')->on('vmod_core_roles');
        });
        Schema::create('vmod_core_membership_branches', function ($table) {
            $table->unsignedBigInteger('membership_id');
            $table->unsignedBigInteger('branch_id');
            $table->primary(['membership_id', 'branch_id']);
            $table->foreign('membership_id')->references('id')->on('vmod_core_memberships');
            $table->foreign('branch_id')->references('id')->on('vmod_core_branches');
        });
        Schema::create('vmod_core_invitations', function ($table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('role_id');
            $table->unsignedBigInteger('intended_user_id')->nullable();
            $table->unsignedBigInteger('created_by_user_id');
            $table->string('email');
            $table->json('branch_ids');
            $table->string('token_hash', 64)->unique();
            $table->timestamp('expires_at');
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
            $table->index(['company_id', 'email']);
            $table->foreign('company_id')->references('id')->on('vmod_core_companies');
            $table->foreign('role_id')->references('id')->on('vmod_core_roles');
            $table->foreign('intended_user_id')->references('id')->on('users');
            $table->foreign('created_by_user_id')->references('id')->on('users');
        });
        Schema::create('vmod_core_audits', function ($table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('actor_user_id')->nullable();
            $table->string('source', 30);
            $table->string('action', 80);
            $table->json('details');
            $table->timestamp('created_at');
            $table->foreign('company_id')->references('id')->on('vmod_core_companies');
            $table->foreign('actor_user_id')->references('id')->on('users');
        });
    }

    public function down()
    {
        foreach (['audits', 'invitations', 'membership_branches', 'memberships', 'roles', 'branches', 'companies'] as $name) {
            Schema::dropIfExists('vmod_core_' . $name);
        }
    }
}
