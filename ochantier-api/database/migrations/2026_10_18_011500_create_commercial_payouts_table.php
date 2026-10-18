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
        Schema::create('commercial_payouts', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->string('reference', 25)->unique();
            $table->foreignUuid('commercial_id')->constrained('commercial_profiles');
            $table->date('period_start');
            $table->date('period_end');
            $table->bigInteger('total_amount');
            $table->foreignUuid('payment_method_id')->nullable()->constrained('payment_methods');
            $table->string('status', 15)->default('a_payer');
            $table->foreignUuid('statement_media_id')->nullable()->constrained('media_files');
            $table->foreignUuid('created_by_admin_id')->nullable()->constrained('admins');
            $table->foreignUuid('approved_by_admin_id')->nullable()->constrained('admins');
            $table->timestampTz('paid_at')->nullable();
            $table->timestampTz('created_at')->useCurrent();
        });

        DB::statement('ALTER TABLE commercial_payouts ADD CONSTRAINT commercial_payouts_total_amount_check CHECK (total_amount >= 0)');
        DB::statement('ALTER TABLE commercial_payouts ADD CONSTRAINT commercial_payouts_status_check CHECK (status IN (\'a_payer\',\'en_traitement\',\'paye\',\'rejete\'))');
        DB::statement('ALTER TABLE commercial_payouts ADD CONSTRAINT commercial_payouts_check1 CHECK (approved_by_admin_id IS NULL OR approved_by_admin_id <> created_by_admin_id)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('commercial_payouts');
    }
};
