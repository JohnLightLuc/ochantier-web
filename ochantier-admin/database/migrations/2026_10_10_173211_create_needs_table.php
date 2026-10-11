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
        Schema::create('needs', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('reference')->unique();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->string('urgency');
            $table->string('type');
            $table->string('source');
            $table->string('admin')->nullable();
            $table->string('district')->nullable();
            $table->string('contact');
            $table->string('contact_phone');
            $table->date('starts_on');
            $table->date('ends_on');
            $table->string('working_hours');
            $table->string('working_days');
            $table->text('justification')->nullable();
            $table->date('coverage_deadline')->nullable();
            $table->string('follow_up')->nullable();
            $table->text('conditions')->nullable();
            $table->json('requirements');
            $table->json('anomalies');
            $table->string('manual_status')->nullable();
            $table->text('reason')->nullable();
            $table->boolean('promoter_validated')->default(false);
            $table->boolean('deposit_paid')->default(false);
            $table->json('details')->nullable();
            $table->date('created_on');
            $table->date('updated_on');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('needs');
    }
};
