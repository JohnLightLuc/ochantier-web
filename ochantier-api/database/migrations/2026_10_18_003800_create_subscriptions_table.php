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
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->string('reference', 25)->unique();
            $table->foreignUuid('promoter_profile_id')->constrained('promoter_profiles');
            $table->foreignUuid('organization_id')->nullable()->constrained('organizations');
            $table->foreignUuid('plan_id')->constrained('subscription_plans');
            $table->foreignUuid('previous_plan_id')->nullable()->constrained('subscription_plans');
            $table->foreignUuid('pending_plan_id')->nullable()->comment('changement de formule programmé')->constrained('subscription_plans');
            $table->date('pending_effective_at')->nullable();
            $table->string('status', 15)->default('active');
            $table->date('started_at');
            $table->date('current_period_start')->nullable();
            $table->date('current_period_end')->nullable();
            $table->date('next_due_date')->nullable();
            $table->boolean('auto_renew')->default(true);
            $table->uuid('payment_method_id')->nullable()->comment('FK ajoutée après la création de payment_methods');
            $table->timestampTz('cancelled_at')->nullable();
            $table->text('cancellation_reason')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();
            $table->comment('Abonnement d\'un promoteur ; pour une entreprise, organization_id est renseigné');
        });

        DB::statement('ALTER TABLE subscriptions ADD CONSTRAINT subscriptions_status_check CHECK (status IN (\'essai\',\'active\',\'en_retard\',\'suspendue\',\'resiliee\',\'expiree\'))');
        DB::statement('ALTER TABLE subscriptions ADD CONSTRAINT subscriptions_check1 CHECK (pending_plan_id IS NULL OR pending_effective_at IS NOT NULL)');
        DB::statement('CREATE UNIQUE INDEX uq_subscriptions_current ON subscriptions (promoter_profile_id) WHERE status IN (\'essai\',\'active\',\'en_retard\',\'suspendue\')');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('subscriptions');
    }
};
