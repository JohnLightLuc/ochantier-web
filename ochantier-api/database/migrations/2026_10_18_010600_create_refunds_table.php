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
        Schema::create('refunds', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->string('reference', 25)->unique();
            $table->foreignUuid('mission_id')->constrained('missions');
            $table->uuid('original_transaction_id')->comment('FK ajoutée après payment_transactions');
            $table->string('beneficiary_type', 30)->comment('promoter_profile, organization');
            $table->uuid('beneficiary_id');
            $table->bigInteger('amount');
            $table->foreignUuid('reason_id')->nullable()->constrained('reason_codes');
            $table->text('reason');
            $table->uuid('dispute_id')->nullable()->comment('FK ajoutée après disputes');
            $table->string('status', 10)->default('demande');
            $table->foreignUuid('requested_by_admin_id')->constrained('admins');
            $table->foreignUuid('approved_by_admin_id')->nullable()->constrained('admins');
            $table->foreignUuid('second_approver_admin_id')->nullable()->constrained('admins');
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();
        });

        DB::statement('ALTER TABLE refunds ADD CONSTRAINT refunds_amount_check CHECK (amount > 0)');
        DB::statement('ALTER TABLE refunds ADD CONSTRAINT refunds_status_check CHECK (status IN (\'demande\',\'approuve\',\'refuse\',\'execute\',\'echoue\'))');
        DB::statement('ALTER TABLE refunds ADD CONSTRAINT refunds_check1 CHECK (approved_by_admin_id IS NULL OR approved_by_admin_id <> requested_by_admin_id)');
        DB::statement('ALTER TABLE refunds ADD CONSTRAINT refunds_check2 CHECK (second_approver_admin_id IS NULL OR second_approver_admin_id NOT IN (requested_by_admin_id, approved_by_admin_id))');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('refunds');
    }
};
