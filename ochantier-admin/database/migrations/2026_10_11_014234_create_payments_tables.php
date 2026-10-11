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
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->string('reference')->unique();
            $table->date('date')->index();
            $table->string('type');
            $table->foreignId('worker_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->string('assignment_reference')->nullable();
            $table->date('period_start');
            $table->date('period_end');
            $table->unsignedInteger('days')->default(0);
            $table->unsignedInteger('open_days')->default(0);
            $table->unsignedInteger('daily_rate')->default(0);
            $table->unsignedInteger('fixed_amount')->nullable();
            $table->string('source');
            $table->string('method')->nullable();
            $table->string('provider')->nullable();
            $table->string('account')->nullable();
            $table->string('worker_method')->nullable();
            $table->string('worker_account')->nullable();
            $table->string('calc_status');
            $table->string('pay_status');
            $table->string('payout_status');
            $table->string('reconciliation');
            $table->text('reconciliation_note')->nullable();
            $table->string('validated_by')->nullable();
            $table->date('validated_at')->nullable();
            $table->date('initiated_at')->nullable();
            $table->date('paid_at')->nullable();
            $table->date('payout_at')->nullable();
            $table->string('external_reference')->nullable();
            $table->string('payout_reference')->nullable();
            $table->string('provider_message')->nullable();
            $table->string('payout_message')->nullable();
            $table->string('batch_reference')->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->unsignedInteger('received_amount')->nullable();
            $table->string('rule_version');
            $table->string('responsible');
            $table->json('attendance_refs');
            $table->json('documents');
            $table->json('reported_anomalies');
            $table->timestamps();
        });

        Schema::create('payment_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_id')->constrained()->cascadeOnDelete();
            $table->string('kind');
            $table->string('type');
            $table->unsignedInteger('amount');
            $table->string('motif');
            $table->string('base')->nullable();
            $table->string('proof')->nullable();
            $table->string('author');
            $table->string('approver')->nullable();
            $table->date('date');
            $table->string('status');
            $table->timestamps();
        });

        Schema::create('payment_refunds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_id')->constrained()->cascadeOnDelete();
            $table->string('type');
            $table->unsignedInteger('amount');
            $table->string('motif');
            $table->string('beneficiary');
            $table->string('responsibility');
            $table->string('rule');
            $table->string('proof')->nullable();
            $table->string('author');
            $table->string('approver')->nullable();
            $table->date('date');
            $table->string('status');
            $table->string('timing');
            $table->unsignedInteger('commission_impact')->nullable();
            $table->timestamps();
        });

        Schema::create('payment_activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_id')->nullable()->constrained()->cascadeOnDelete();
            $table->dateTime('occurred_at')->index();
            $table->string('author');
            $table->string('category');
            $table->string('action');
            $table->string('old_value')->nullable();
            $table->string('new_value')->nullable();
            $table->text('comment')->nullable();
            $table->string('origin');
            $table->timestamps();
        });

        Schema::create('payout_batches', function (Blueprint $table) {
            $table->id();
            $table->string('reference')->unique();
            $table->dateTime('executed_at');
            $table->string('author');
            $table->json('results');
            $table->json('blocked');
            $table->unsignedBigInteger('net');
            $table->unsignedBigInteger('commission');
            $table->string('status');
            $table->timestamps();
        });

        Schema::create('payment_notifications', function (Blueprint $table) {
            $table->id();
            $table->string('type');
            $table->string('recipient');
            $table->string('channel');
            $table->dateTime('sent_at');
            $table->text('content');
            $table->string('status');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        foreach (['payment_notifications', 'payout_batches', 'payment_activities', 'payment_refunds', 'payment_items', 'payments'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
