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
        Schema::create('analytics_reports', function (Blueprint $table) {
            $table->id();
            $table->string('kind');
            $table->string('name');
            $table->string('format');
            $table->unsignedInteger('rows')->default(0);
            $table->string('size');
            $table->date('generated_on')->index();
            $table->string('author');
            $table->string('filters');
            $table->string('rhythm');
            $table->timestamps();
        });

        Schema::create('analytics_schedules', function (Blueprint $table) {
            $table->id();
            $table->string('kind');
            $table->string('frequency');
            $table->date('next_run');
            $table->string('recipients');
            $table->string('format');
            $table->string('status');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('analytics_schedules');
        Schema::dropIfExists('analytics_reports');
    }
};
