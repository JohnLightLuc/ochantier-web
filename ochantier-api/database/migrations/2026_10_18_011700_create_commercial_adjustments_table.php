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
        Schema::create('commercial_adjustments', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('commercial_id')->constrained('commercial_profiles');
            $table->string('kind', 10);
            $table->bigInteger('amount');
            $table->text('reason');
            $table->date('granted_on');
            $table->string('status', 10)->default('proposee');
            $table->foreignUuid('created_by_admin_id')->constrained('admins');
            $table->foreignUuid('approved_by_admin_id')->nullable()->constrained('admins');
            $table->foreignUuid('commercial_payout_id')->nullable()->constrained('commercial_payouts');
            $table->timestampTz('created_at')->useCurrent();
        });

        DB::statement('ALTER TABLE commercial_adjustments ADD CONSTRAINT commercial_adjustments_kind_check CHECK (kind IN (\'prime\',\'retenue\'))');
        DB::statement('ALTER TABLE commercial_adjustments ADD CONSTRAINT commercial_adjustments_amount_check CHECK (amount > 0)');
        DB::statement('ALTER TABLE commercial_adjustments ADD CONSTRAINT commercial_adjustments_status_check CHECK (status IN (\'proposee\',\'approuvee\',\'refusee\',\'payee\',\'annulee\'))');
        DB::statement('ALTER TABLE commercial_adjustments ADD CONSTRAINT commercial_adjustments_check1 CHECK (approved_by_admin_id IS NULL OR approved_by_admin_id <> created_by_admin_id)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('commercial_adjustments');
    }
};
