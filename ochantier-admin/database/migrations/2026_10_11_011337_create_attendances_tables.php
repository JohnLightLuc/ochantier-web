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
        Schema::create('attendances', function (Blueprint $table) {
            $table->id();
            $table->string('reference')->unique();
            $table->date('date')->index();
            $table->foreignId('worker_id')->constrained()->cascadeOnDelete();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->string('assignment_reference');
            $table->string('planned_start', 5);
            $table->string('planned_end', 5);
            $table->boolean('night')->default(false);
            $table->json('arrival');
            $table->json('departure');
            $table->json('pauses');
            $table->json('absence')->nullable();
            $table->json('lateness')->nullable();
            $table->json('early_leave')->nullable();
            $table->json('overtime')->nullable();
            $table->json('closure');
            $table->string('transmission')->default('Non transmise');
            $table->unsignedInteger('reminders')->default(0);
            $table->json('notes');
            $table->dateTime('last_update');
            $table->timestamps();
        });

        Schema::create('attendance_anomalies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('attendance_id')->constrained()->cascadeOnDelete();
            $table->string('type');
            $table->string('level');
            $table->string('status');
            $table->date('detected_on');
            $table->text('note');
            $table->text('decision')->nullable();
            $table->timestamps();
        });

        Schema::create('attendance_corrections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('attendance_id')->constrained()->cascadeOnDelete();
            $table->string('field');
            $table->string('old_value');
            $table->string('new_value');
            $table->string('motif');
            $table->text('comment')->nullable();
            $table->string('proof')->nullable();
            $table->string('status');
            $table->string('requested_by');
            $table->dateTime('requested_at');
            $table->text('decision')->nullable();
            $table->string('impact')->nullable();
            $table->timestamps();
        });

        Schema::create('attendance_activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('attendance_id')->constrained()->cascadeOnDelete();
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
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('attendance_activities');
        Schema::dropIfExists('attendance_corrections');
        Schema::dropIfExists('attendance_anomalies');
        Schema::dropIfExists('attendances');
    }
};
