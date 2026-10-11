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
        Schema::create('reviews', function (Blueprint $table) {
            $table->id();
            $table->string('reference')->unique();
            $table->string('kind');
            $table->string('author');
            $table->string('author_type');
            $table->foreignId('promoter_id')->nullable()->constrained()->nullOnDelete();
            $table->string('promoter_name');
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->foreignId('worker_id')->nullable()->constrained()->nullOnDelete();
            $table->string('target');
            $table->string('target_type');
            $table->json('team')->nullable();
            $table->string('operation_reference')->index();
            $table->string('assignment_reference');
            $table->string('trade');
            $table->string('prestation');
            $table->date('period_from');
            $table->date('period_to');
            $table->date('date')->index();
            $table->string('weights_version');
            $table->json('criteria');
            $table->text('comment')->nullable();
            $table->string('positives')->nullable();
            $table->string('negatives')->nullable();
            $table->string('recommends');
            $table->json('proofs');
            $table->boolean('publish_consent')->default(true);
            $table->boolean('sincere')->default(true);
            $table->string('verification');
            $table->string('status');
            $table->string('visibility');
            $table->json('reply');
            $table->json('detections');
            $table->json('reports');
            $table->boolean('is_test')->default(false);
            $table->string('duplicate_of')->nullable();
            $table->boolean('fraud')->default(false);
            $table->json('eligibility');
            $table->string('device');
            $table->json('moderation')->nullable();
            $table->text('modification_request')->nullable();
            $table->string('dispute_reference')->nullable();
            $table->timestamps();
        });

        Schema::create('disputes', function (Blueprint $table) {
            $table->id();
            $table->string('reference')->unique();
            $table->string('subject');
            $table->string('category');
            $table->string('subcategory')->nullable();
            $table->text('description');
            $table->date('facts_at');
            $table->date('opened_at')->index();
            $table->string('source');
            $table->string('source_reference')->nullable();
            $table->json('claimant');
            $table->json('respondent');
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->foreignId('promoter_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('worker_id')->nullable()->constrained()->nullOnDelete();
            $table->string('operation_reference');
            $table->string('assignment_reference');
            $table->string('prestation');
            $table->string('trade');
            $table->unsignedInteger('amount')->default(0);
            $table->string('wish');
            $table->boolean('urgent')->default(false);
            $table->string('priority');
            $table->string('severity');
            $table->text('priority_reason')->nullable();
            $table->string('owner')->nullable();
            $table->string('status');
            $table->date('qualified_at')->nullable();
            $table->date('responded_at')->nullable();
            $table->unsignedInteger('first_response_hours')->nullable();
            $table->date('phase_started_at')->nullable();
            $table->text('suspension')->nullable();
            $table->date('decided_at')->nullable();
            $table->date('resolved_at')->nullable();
            $table->date('closed_at')->nullable();
            $table->unsignedInteger('reopen_count')->default(0);
            $table->json('admissibility');
            $table->text('inadmissibility')->nullable();
            $table->json('parties');
            $table->json('declarations');
            $table->json('proofs');
            $table->json('mediation');
            $table->json('decision')->nullable();
            $table->json('appeal_decision')->nullable();
            $table->json('financial_requests');
            $table->json('replacements');
            $table->json('attendance_requests');
            $table->json('appeals');
            $table->json('notes');
            $table->timestamps();
        });

        Schema::create('claims', function (Blueprint $table) {
            $table->id();
            $table->string('reference')->unique();
            $table->json('claimant');
            $table->json('other_party');
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->string('assignment_reference');
            $table->string('operation_reference');
            $table->string('category');
            $table->string('subject');
            $table->text('description');
            $table->date('date')->index();
            $table->string('priority');
            $table->string('owner');
            $table->date('due_date');
            $table->string('status');
            $table->foreignId('dispute_id')->nullable()->constrained()->nullOnDelete();
            $table->text('resolution')->nullable();
            $table->string('review_reference')->nullable();
            $table->timestamps();
        });

        Schema::create('quality_activities', function (Blueprint $table) {
            $table->id();
            $table->string('subject_reference')->nullable()->index();
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

        Schema::create('quality_notifications', function (Blueprint $table) {
            $table->id();
            $table->dateTime('sent_at')->index();
            $table->string('recipient');
            $table->string('channel');
            $table->text('content');
            $table->string('expected_action');
            $table->string('reference')->nullable();
            $table->string('status');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        foreach (['quality_notifications', 'quality_activities', 'claims', 'disputes', 'reviews'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
