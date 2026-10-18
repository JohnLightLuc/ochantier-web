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
        Schema::create('commercial_zones', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('commercial_id')->constrained('commercial_profiles');
            $table->foreignUuid('commune_id')->constrained('communes');
            $table->boolean('is_primary')->default(false);
            $table->date('effective_from')->default(DB::raw('CURRENT_DATE'));
            $table->date('effective_to')->nullable();
            $table->foreignUuid('assigned_by_admin_id')->nullable()->constrained('admins');
            $table->timestampTz('created_at')->useCurrent();
            $table->index(['commercial_id'], 'ix_commercial_zones_commercial');
        });

        DB::statement('ALTER TABLE commercial_zones ADD CONSTRAINT commercial_zones_check1 CHECK (effective_to IS NULL OR effective_to >= effective_from)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('commercial_zones');
    }
};
