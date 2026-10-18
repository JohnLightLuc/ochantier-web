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
        Schema::create('worker_payout_batches', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->string('reference', 25)->unique();
            $table->string('status', 12)->default('brouillon');
            $table->integer('payout_count')->default(0);
            $table->bigInteger('total_net_amount')->default(0);
            $table->bigInteger('total_commission_amount')->default(0);
            $table->jsonb('results')->default('{}')->comment('réussis, échoués, bloqués');
            $table->foreignUuid('created_by_admin_id')->constrained('admins');
            $table->foreignUuid('approved_by_admin_id')->nullable()->constrained('admins');
            $table->timestampTz('executed_at')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->comment('Lot de reversements exécuté en une fois auprès du prestataire');
        });

        DB::statement('ALTER TABLE worker_payout_batches ADD CONSTRAINT worker_payout_batches_status_check CHECK (status IN (\'brouillon\',\'a_valider\',\'en_execution\',\'termine\',\'partiel\',\'echoue\',\'annule\'))');
        DB::statement('ALTER TABLE worker_payout_batches ADD CONSTRAINT worker_payout_batches_check1 CHECK (approved_by_admin_id IS NULL OR approved_by_admin_id <> created_by_admin_id)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('worker_payout_batches');
    }
};
