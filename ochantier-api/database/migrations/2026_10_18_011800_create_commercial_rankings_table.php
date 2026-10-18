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
        Schema::create('commercial_rankings', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->date('period_month')->unique();
            $table->jsonb('criteria')->default('{}');
            $table->timestampTz('closed_at')->nullable();
            $table->timestampTz('published_at')->nullable();
            $table->foreignUuid('created_by_admin_id')->nullable()->constrained('admins');
            $table->timestampTz('created_at')->useCurrent();
        });

        DB::statement('ALTER TABLE commercial_rankings ADD CONSTRAINT commercial_rankings_period_month_check CHECK (EXTRACT(DAY FROM period_month) = 1)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('commercial_rankings');
    }
};
