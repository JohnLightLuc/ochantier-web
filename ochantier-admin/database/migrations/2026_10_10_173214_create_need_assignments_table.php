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
        Schema::create('need_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('need_id')->constrained()->cascadeOnDelete();
            $table->foreignId('need_offer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('worker_id')->nullable()->constrained()->nullOnDelete();
            $table->string('reference')->unique();
            $table->string('worker_name');
            $table->string('trade');
            $table->string('commune')->nullable();
            $table->string('phone')->nullable();
            $table->unsignedInteger('daily_cost')->default(0);
            $table->string('status');
            $table->string('source');
            $table->date('selected_on');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('need_assignments');
    }
};
