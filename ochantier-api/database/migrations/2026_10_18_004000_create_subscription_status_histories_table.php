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
        Schema::create('subscription_status_histories', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('subscription_id')->constrained('subscriptions')->cascadeOnDelete();
            $table->string('from_status', 15)->nullable();
            $table->string('to_status', 15);
            $table->foreignUuid('from_plan_id')->nullable()->constrained('subscription_plans');
            $table->foreignUuid('to_plan_id')->nullable()->constrained('subscription_plans');
            $table->text('reason')->nullable();
            $table->string('actor_type', 20);
            $table->uuid('actor_id')->nullable();
            $table->timestampTz('created_at')->useCurrent();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('subscription_status_histories');
    }
};
