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
        Schema::create('subscription_invoices', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->string('reference', 25)->unique();
            $table->foreignUuid('subscription_id')->constrained('subscriptions');
            $table->foreignUuid('plan_id')->constrained('subscription_plans');
            $table->string('label', 200);
            $table->date('period_start');
            $table->date('period_end');
            $table->bigInteger('amount');
            $table->date('due_date');
            $table->string('status', 12)->default('a_payer');
            $table->timestampTz('paid_at')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();
            $table->unique(['subscription_id', 'period_start']);
        });

        DB::statement('ALTER TABLE subscription_invoices ADD CONSTRAINT subscription_invoices_amount_check CHECK (amount >= 0)');
        DB::statement('ALTER TABLE subscription_invoices ADD CONSTRAINT subscription_invoices_status_check CHECK (status IN (\'a_payer\',\'payee\',\'en_retard\',\'annulee\',\'remboursee\'))');
        DB::statement('ALTER TABLE subscription_invoices ADD CONSTRAINT subscription_invoices_check1 CHECK (period_end >= period_start)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('subscription_invoices');
    }
};
