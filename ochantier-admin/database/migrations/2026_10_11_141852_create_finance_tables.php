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
        Schema::create('finance_archives', function (Blueprint $table) {
            $table->id();
            $table->string('reference')->unique();
            $table->string('operation_reference');
            $table->string('external_reference')->nullable();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->foreignId('worker_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type');
            $table->boolean('is_test')->default(false);
            $table->date('service_date')->index();
            $table->date('initiated_at')->nullable();
            $table->date('paid_at')->nullable();
            $table->date('collected_at')->nullable();
            $table->date('payout_at')->nullable();
            $table->date('reconciled_at')->nullable();
            $table->unsignedInteger('days')->default(0);
            $table->unsignedInteger('daily_rate')->default(0);
            $table->unsignedInteger('promoter_rate');
            $table->unsignedInteger('worker_rate');
            $table->string('method');
            $table->string('provider');
            $table->string('worker_method')->nullable();
            $table->string('pay_status');
            $table->string('payout_status');
            $table->string('commission_status');
            $table->string('reconciliation');
            $table->unsignedInteger('attempts')->default(1);
            $table->string('message')->nullable();
            $table->json('refunds');
            $table->string('rule_version');
            $table->string('responsible');
            $table->timestamps();
        });

        Schema::create('finance_closings', function (Blueprint $table) {
            $table->id();
            $table->string('month')->unique();
            $table->string('status');
            $table->unsignedInteger('version')->default(0);
            $table->json('snapshot')->nullable();
            $table->json('history');
            $table->text('reason')->nullable();
            $table->timestamps();
        });

        Schema::create('finance_views', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->json('filters');
            $table->string('basis');
            $table->string('period');
            $table->string('author');
            $table->json('shared');
            $table->timestamps();
        });

        Schema::create('finance_reports', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('format');
            $table->string('period');
            $table->string('basis');
            $table->string('author');
            $table->unsignedInteger('rows');
            $table->dateTime('generated_at');
            $table->timestamps();
        });

        Schema::create('finance_audits', function (Blueprint $table) {
            $table->id();
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

        Schema::create('finance_resolutions', function (Blueprint $table) {
            $table->id();
            $table->string('reference');
            $table->string('kind');
            $table->text('reason');
            $table->string('author');
            $table->timestamps();
            $table->unique(['reference', 'kind']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        foreach (['finance_resolutions', 'finance_audits', 'finance_reports', 'finance_views', 'finance_closings', 'finance_archives'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
