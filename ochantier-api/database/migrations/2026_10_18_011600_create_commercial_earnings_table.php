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
        Schema::create('commercial_earnings', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('commercial_id')->constrained('commercial_profiles');
            $table->foreignUuid('rule_id')->constrained('commercial_earning_rules');
            $table->foreignUuid('worker_id')->constrained('workers');
            $table->string('event_type', 30);
            $table->timestampTz('event_at');
            $table->date('week_start')->comment('lundi de la semaine (vue hebdomadaire)');
            $table->bigInteger('amount');
            $table->string('status', 15)->default('en_attente');
            $table->text('pending_reason')->nullable();
            $table->foreignUuid('approved_by_admin_id')->nullable()->constrained('admins');
            $table->timestampTz('approved_at')->nullable();
            $table->foreignUuid('commercial_payout_id')->nullable()->constrained('commercial_payouts');
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();
            $table->unique(['worker_id', 'event_type']);
            $table->index(['commercial_id', 'week_start'], 'ix_commercial_earnings_commercial');
        });

        DB::statement('ALTER TABLE commercial_earnings ADD CONSTRAINT commercial_earnings_amount_check CHECK (amount >= 0)');
        DB::statement('ALTER TABLE commercial_earnings ADD CONSTRAINT commercial_earnings_status_check CHECK (status IN (\'estime\',\'en_attente\',\'acquis\',\'approuve\',\'paye\',\'rejete\',\'annule\'))');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('commercial_earnings');
    }
};
