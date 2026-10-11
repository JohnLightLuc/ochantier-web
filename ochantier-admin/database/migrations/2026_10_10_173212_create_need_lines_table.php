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
        Schema::create('need_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('need_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('position');
            $table->string('trade_group');
            $table->string('trade');
            $table->string('specialty')->nullable();
            $table->unsignedInteger('quantity');
            $table->string('experience');
            $table->unsignedInteger('daily_cost')->default(0);
            $table->string('pay_type');
            $table->json('skills');
            $table->json('certifications');
            $table->boolean('own_equipment')->default(false);
            $table->string('tools')->nullable();
            $table->string('constraints')->nullable();
            $table->text('comment')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('need_lines');
    }
};
