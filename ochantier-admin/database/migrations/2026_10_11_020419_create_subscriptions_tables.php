<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('subscription_plans', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->string('name');
            $table->unsignedInteger('max_users');
            $table->unsignedInteger('price');
            $table->string('period');
            $table->string('subtitle');
            $table->string('description');
            $table->json('features');
            $table->boolean('popular')->default(false);
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();
        });

        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('promoter_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('plan_key');
            $table->string('previous_plan_key')->nullable();
            $table->string('status');
            $table->date('since');
            $table->date('next_due')->nullable();
            $table->string('payment_method')->nullable();
            $table->string('pending_plan_key')->nullable();
            $table->date('pending_at')->nullable();
            $table->timestamps();
        });

        Schema::create('subscription_users', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subscription_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('role');
            $table->string('via');
            $table->string('contact')->nullable();
            $table->string('status');
            $table->boolean('owner')->default(false);
            $table->timestamps();
        });

        Schema::create('subscription_invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subscription_id')->constrained()->cascadeOnDelete();
            $table->string('reference');
            $table->date('date');
            $table->unsignedInteger('amount');
            $table->string('label');
            $table->string('status');
            $table->timestamps();
        });

        Schema::create('subscription_activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subscription_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->string('text');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        foreach (['subscription_activities', 'subscription_invoices', 'subscription_users', 'subscriptions', 'subscription_plans'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
