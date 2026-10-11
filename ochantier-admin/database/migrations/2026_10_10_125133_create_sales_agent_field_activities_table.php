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
        Schema::create('sales_agent_field_activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sales_agent_id')->constrained()->cascadeOnDelete();
            $table->foreignId('worker_id')->nullable()->constrained()->nullOnDelete();
            $table->dateTime('occurred_at');
            $table->string('type');
            $table->string('location');
            $table->text('comment');
            $table->boolean('has_proof')->default(false);
            $table->string('status');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sales_agent_field_activities');
    }
};
