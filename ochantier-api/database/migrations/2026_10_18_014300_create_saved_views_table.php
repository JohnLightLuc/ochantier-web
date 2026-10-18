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
        Schema::create('saved_views', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('admin_id')->constrained('admins');
            $table->string('module', 40);
            $table->string('name', 120);
            $table->jsonb('filters')->default('{}');
            $table->boolean('is_shared')->default(false);
            $table->rawColumn('shared_with', 'uuid[]')->default(DB::raw('\'{}\''))->comment('administrateurs destinataires');
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();
            $table->unique(['admin_id', 'module', 'name']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('saved_views');
    }
};
