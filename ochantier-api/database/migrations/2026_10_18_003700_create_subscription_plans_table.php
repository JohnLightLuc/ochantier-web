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
        Schema::create('subscription_plans', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->string('code', 30)->unique();
            $table->string('name', 80);
            $table->string('subtitle', 160)->nullable();
            $table->text('description')->nullable();
            $table->bigInteger('price_amount')->comment('XOF par période');
            $table->string('billing_period', 10);
            $table->integer('max_users')->comment('places (membres de l\'organisation)');
            $table->jsonb('features')->default('[]');
            $table->boolean('is_featured')->default(false);
            $table->integer('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();
            $table->comment('Formules d\'abonnement des promoteurs (distinctes de la commission Y)');
        });

        DB::statement('ALTER TABLE subscription_plans ADD CONSTRAINT subscription_plans_price_amount_check CHECK (price_amount >= 0)');
        DB::statement('ALTER TABLE subscription_plans ADD CONSTRAINT subscription_plans_billing_period_check CHECK (billing_period IN (\'mois\',\'trimestre\',\'annee\'))');
        DB::statement('ALTER TABLE subscription_plans ADD CONSTRAINT subscription_plans_max_users_check CHECK (max_users > 0)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('subscription_plans');
    }
};
