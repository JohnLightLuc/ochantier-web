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
        Schema::create('sales_agent_objectives', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sales_agent_id')->constrained()->cascadeOnDelete();
            $table->string('label');
            $table->string('type');
            $table->unsignedInteger('target');
            $table->date('starts_on');
            $table->date('ends_on');
            $table->boolean('closed')->default(false);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sales_agent_objectives');
    }
};
