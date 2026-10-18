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
        Schema::create('payout_adjustments', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('worker_payout_id')->constrained('worker_payouts')->cascadeOnDelete();
            $table->string('kind', 10);
            $table->bigInteger('amount');
            $table->text('reason');
            $table->text('basis')->nullable();
            $table->foreignUuid('proof_media_id')->nullable()->constrained('media_files');
            $table->string('status', 10)->default('proposee');
            $table->foreignUuid('created_by_admin_id')->constrained('admins');
            $table->foreignUuid('approved_by_admin_id')->nullable()->constrained('admins');
            $table->timestampTz('created_at')->useCurrent();
            $table->comment('Retenue ou bonus appliqué à un reversement, motivé et approuvé');
        });

        DB::statement('ALTER TABLE payout_adjustments ADD CONSTRAINT payout_adjustments_kind_check CHECK (kind IN (\'retenue\',\'bonus\'))');
        DB::statement('ALTER TABLE payout_adjustments ADD CONSTRAINT payout_adjustments_amount_check CHECK (amount > 0)');
        DB::statement('ALTER TABLE payout_adjustments ADD CONSTRAINT payout_adjustments_status_check CHECK (status IN (\'proposee\',\'approuvee\',\'refusee\',\'annulee\'))');
        DB::statement('ALTER TABLE payout_adjustments ADD CONSTRAINT payout_adjustments_check1 CHECK (approved_by_admin_id IS NULL OR approved_by_admin_id <> created_by_admin_id)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payout_adjustments');
    }
};
