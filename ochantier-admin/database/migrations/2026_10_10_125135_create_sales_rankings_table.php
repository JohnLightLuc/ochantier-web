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
        Schema::create('sales_rankings', function (Blueprint $table) {
            $table->id();
            // Mois du classement, au format « 2026-09 »
            $table->string('month', 7)->unique();
            $table->dateTime('closed_at')->nullable();
            $table->boolean('published')->default(false);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sales_rankings');
    }
};
