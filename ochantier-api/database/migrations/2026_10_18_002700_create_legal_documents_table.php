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
        Schema::create('legal_documents', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->string('code', 40)->comment('cgu, privacy, data_processing, photo_usage, financial_terms, commercial_charter');
            $table->string('audience', 20);
            $table->string('version', 20);
            $table->string('title', 200);
            $table->text('content')->nullable();
            $table->foreignUuid('media_file_id')->nullable()->constrained('media_files');
            $table->timestampTz('effective_from');
            $table->foreignUuid('published_by_admin_id')->nullable()->constrained('admins');
            $table->timestampTz('created_at')->useCurrent();
            $table->unique(['code', 'version']);
            $table->comment('Textes applicables versionnés (CGU, confidentialité, conditions financières…)');
        });

        DB::statement('ALTER TABLE legal_documents ADD CONSTRAINT legal_documents_audience_check CHECK (audience IN (\'promoter\',\'commercial\',\'worker\',\'all\'))');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('legal_documents');
    }
};
