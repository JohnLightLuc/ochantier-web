<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('document_files', function (Blueprint $table) {
            $table->foreignUuid('document_id')->constrained('documents')->cascadeOnDelete();
            $table->foreignUuid('media_file_id')->constrained('media_files');
            $table->string('side', 10)->default('page');
            $table->smallInteger('page_number')->default(1);
            $table->primary(['document_id', 'media_file_id']);
        });

        DB::statement('ALTER TABLE document_files ADD CONSTRAINT document_files_side_check CHECK (side IN (\'recto\',\'verso\',\'page\'))');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('document_files');
    }
};
