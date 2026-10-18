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
        Schema::create('media_files', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->string('disk', 30)->default('s3');
            $table->text('path');
            $table->string('original_name', 255)->nullable();
            $table->string('mime_type', 100);
            $table->bigInteger('size_bytes');
            $table->char('sha256', 64)->comment('empreinte (anti-réutilisation de preuve)');
            $table->integer('width')->nullable();
            $table->integer('height')->nullable();
            $table->string('purpose', 30)->nullable()->comment('photo_profil, piece_identite, signature, pointage, recu…');
            $table->boolean('is_sensitive')->default(true)->comment('jamais d\'URL publique permanente');
            $table->timestampTz('captured_at')->nullable();
            $table->string('uploaded_by_type', 20)->nullable();
            $table->uuid('uploaded_by_id')->nullable();
            $table->uuid('client_uuid')->nullable()->unique();
            $table->jsonb('metadata')->default('{}');
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('deleted_at')->nullable();
            $table->index(['sha256'], 'ix_media_files_sha256');
            $table->comment('Tout fichier stocké (stockage objet S3) : photos, signatures, pièces, reçus');
        });

        DB::statement('ALTER TABLE media_files ADD CONSTRAINT media_files_size_bytes_check CHECK (size_bytes >= 0)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('media_files');
    }
};
