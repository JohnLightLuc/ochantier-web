<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('site_members', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('site_id')->constrained('sites');
            $table->foreignUuid('user_id')->constrained('users');
            $table->boolean('can_manage')->default(false);
            $table->boolean('can_check_in')->default(false)->comment('autorisé à pointer');
            $table->boolean('can_pay')->default(false);
            $table->foreignUuid('granted_by_user_id')->nullable()->constrained('users');
            $table->timestampTz('granted_at')->useCurrent();
            $table->timestampTz('revoked_at')->nullable();
            $table->unique(['site_id', 'user_id']);
            $table->comment('Personnes autorisées sur un chantier (gestion, pointage, paiement)');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('site_members');
    }
};
