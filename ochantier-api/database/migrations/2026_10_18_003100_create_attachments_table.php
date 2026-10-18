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
        Schema::create('attachments', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->string('attachable_type', 30);
            $table->uuid('attachable_id');
            $table->foreignUuid('media_file_id')->constrained('media_files');
            $table->string('label', 200)->nullable();
            $table->string('created_by_type', 20)->nullable();
            $table->uuid('created_by_id')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->index(['attachable_type', 'attachable_id'], 'ix_attachments_owner');
            $table->comment('Pièces jointes non typées (tickets, litiges, comptes rendus…)');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('attachments');
    }
};
