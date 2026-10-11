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
        Schema::create('need_offers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('need_id')->constrained()->cascadeOnDelete();
            $table->foreignId('need_line_id')->constrained()->cascadeOnDelete();
            $table->string('reference')->unique();
            $table->string('title');
            $table->string('urgency');
            $table->string('visibility');
            $table->date('published_on');
            $table->date('deadline');
            $table->date('starts_on');
            $table->date('ends_on');
            $table->boolean('applications_open')->default(true);
            $table->string('manual_status')->nullable();
            $table->unsignedInteger('extensions')->default(0);
            $table->unsignedInteger('refreshes')->default(0);
            $table->boolean('auto_renew')->default(false);
            $table->text('description')->nullable();
            $table->string('benefits')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('need_offers');
    }
};
