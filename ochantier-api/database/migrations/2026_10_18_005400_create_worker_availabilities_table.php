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
        Schema::create('worker_availabilities', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('worker_id')->constrained('workers')->cascadeOnDelete();
            $table->string('status', 15);
            $table->timestampTz('effective_from')->useCurrent();
            $table->timestampTz('effective_to')->nullable();
            $table->date('next_available_date')->nullable();
            $table->string('source', 15);
            $table->string('recorded_by_type', 20);
            $table->uuid('recorded_by_id')->nullable();
            $table->string('confirmation_channel', 15)->nullable();
            $table->foreignUuid('proof_media_id')->nullable()->constrained('media_files');
            $table->text('comment')->nullable();
            $table->uuid('client_uuid')->nullable()->unique();
            $table->timestampTz('created_at')->useCurrent();
            $table->comment('Historique de disponibilité (source, preuve, auteur)');
        });

        DB::statement('ALTER TABLE worker_availabilities ADD CONSTRAINT worker_availabilities_status_check CHECK (status IN (\'disponible\',\'occupe\',\'indisponible\'))');
        DB::statement('ALTER TABLE worker_availabilities ADD CONSTRAINT worker_availabilities_source_check CHECK (source IN (\'commercial\',\'back_office\',\'ouvrier\',\'systeme\'))');
        DB::statement('ALTER TABLE worker_availabilities ADD CONSTRAINT worker_availabilities_confirmation_channel_check CHECK (confirmation_channel IN (\'appel\',\'sms\',\'visite\',\'application\'))');
        DB::statement('CREATE INDEX ix_worker_availabilities_worker ON worker_availabilities (worker_id, effective_from DESC)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('worker_availabilities');
    }
};
