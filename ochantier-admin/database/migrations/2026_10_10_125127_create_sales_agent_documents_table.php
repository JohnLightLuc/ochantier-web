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
        Schema::create('sales_agent_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sales_agent_id')->constrained()->cascadeOnDelete();
            $table->string('type');
            $table->string('reference')->nullable();
            $table->string('file_name')->nullable();
            $table->string('file_path')->nullable();
            $table->date('added_at')->nullable();
            $table->date('expires_at')->nullable();
            $table->string('status');
            $table->text('rejection_reason')->nullable();
            $table->text('internal_comment')->nullable();
            $table->timestamps();

            $table->unique(['sales_agent_id', 'type']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sales_agent_documents');
    }
};
