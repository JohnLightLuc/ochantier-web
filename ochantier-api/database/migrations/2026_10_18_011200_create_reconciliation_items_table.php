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
        Schema::create('reconciliation_items', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('run_id')->constrained('reconciliation_runs')->cascadeOnDelete();
            $table->foreignUuid('transaction_id')->nullable()->constrained('payment_transactions');
            $table->string('provider_reference', 120)->nullable();
            $table->bigInteger('provider_amount')->nullable();
            $table->bigInteger('platform_amount')->nullable();
            $table->string('discrepancy_type', 25);
            $table->string('status', 10)->default('ouvert');
            $table->text('resolution')->nullable();
            $table->foreignUuid('resolved_by_admin_id')->nullable()->constrained('admins');
            $table->timestampTz('resolved_at')->nullable();
        });

        DB::statement('ALTER TABLE reconciliation_items ADD CONSTRAINT reconciliation_items_discrepancy_type_check CHECK (discrepancy_type IN (\'ecart_montant\',\'absent_plateforme\',\'absent_prestataire\',\'doublon\',\'statut_different\'))');
        DB::statement('ALTER TABLE reconciliation_items ADD CONSTRAINT reconciliation_items_status_check CHECK (status IN (\'ouvert\',\'resolu\',\'accepte\'))');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('reconciliation_items');
    }
};
