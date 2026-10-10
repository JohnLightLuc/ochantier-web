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
        Schema::create('promoter_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('promoter_id')->constrained()->cascadeOnDelete();
            $table->string('type');
            $table->string('reference')->nullable();
            $table->date('added_at')->nullable();
            $table->date('expires_at')->nullable();
            $table->string('status');
            $table->text('rejection_reason')->nullable();
            $table->string('file_path')->nullable();
            $table->timestamps();

            $table->unique(['promoter_id', 'type']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('promoter_documents');
    }
};
