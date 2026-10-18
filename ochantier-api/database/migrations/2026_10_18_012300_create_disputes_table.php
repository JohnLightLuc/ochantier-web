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
        Schema::create('disputes', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->string('reference', 25)->unique();
            $table->string('category', 30)->comment('prestation, paiement, pointage, avis, gain_commercial…');
            $table->string('opened_by_type', 20);
            $table->uuid('opened_by_id');
            $table->foreignUuid('site_id')->nullable()->constrained('sites');
            $table->foreignUuid('mission_id')->nullable()->constrained('missions');
            $table->foreignUuid('assignment_id')->nullable()->constrained('assignments');
            $table->foreignUuid('transaction_id')->nullable()->constrained('payment_transactions');
            $table->foreignUuid('rating_id')->nullable()->constrained('ratings');
            $table->foreignUuid('commercial_earning_id')->nullable()->constrained('commercial_earnings');
            $table->text('description');
            $table->string('urgency', 10)->default('normale');
            $table->string('status', 15)->default('ouvert');
            $table->foreignUuid('assigned_admin_id')->nullable()->constrained('admins');
            $table->text('decision')->nullable();
            $table->jsonb('financial_impact')->nullable();
            $table->foreignUuid('decided_by_admin_id')->nullable()->constrained('admins');
            $table->timestampTz('decided_at')->nullable();
            $table->uuid('appeal_of_dispute_id')->nullable();
            $table->timestampTz('appeal_deadline')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();
        });

        Schema::table('disputes', function (Blueprint $table) {
            $table->foreign('appeal_of_dispute_id')->references('id')->on('disputes');
        });

        DB::statement('ALTER TABLE disputes ADD CONSTRAINT disputes_urgency_check CHECK (urgency IN (\'basse\',\'normale\',\'haute\',\'critique\'))');
        DB::statement('ALTER TABLE disputes ADD CONSTRAINT disputes_status_check CHECK (status IN (\'ouvert\',\'en_instruction\',\'resolu\',\'clos\',\'en_recours\'))');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('disputes');
    }
};
