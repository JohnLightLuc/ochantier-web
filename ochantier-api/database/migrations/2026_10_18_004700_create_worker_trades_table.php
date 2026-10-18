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
        Schema::create('worker_trades', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('worker_id')->constrained('workers')->cascadeOnDelete();
            $table->foreignUuid('trade_id')->constrained('trades');
            $table->boolean('is_primary')->default(false);
            $table->smallInteger('years_experience');
            $table->smallInteger('verified_years')->nullable()->comment('expérience vérifiée par le Back Office');
            $table->smallInteger('start_year')->nullable();
            $table->string('level', 15);
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();
            $table->unique(['worker_id', 'trade_id']);
            $table->index(['trade_id'], 'ix_worker_trades_trade');
        });

        DB::statement('ALTER TABLE worker_trades ADD CONSTRAINT worker_trades_years_experience_check CHECK (years_experience >= 0)');
        DB::statement('ALTER TABLE worker_trades ADD CONSTRAINT worker_trades_verified_years_check CHECK (verified_years >= 0)');
        DB::statement('ALTER TABLE worker_trades ADD CONSTRAINT worker_trades_level_check CHECK (level IN (\'debutant\',\'intermediaire\',\'confirme\',\'expert\'))');
        DB::statement('CREATE UNIQUE INDEX uq_worker_trades_primary ON worker_trades (worker_id) WHERE is_primary');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('worker_trades');
    }
};
