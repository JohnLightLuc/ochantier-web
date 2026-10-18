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
        Schema::create('app_versions', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->string('app_name', 15);
            $table->string('platform', 10);
            $table->string('min_version', 20);
            $table->string('latest_version', 20);
            $table->boolean('force_update')->default(false);
            $table->text('message')->nullable();
            $table->text('store_url')->nullable();
            $table->timestampTz('updated_at')->useCurrent();
            $table->unique(['app_name', 'platform']);
        });

        DB::statement('ALTER TABLE app_versions ADD CONSTRAINT app_versions_app_name_check CHECK (app_name IN (\'promoteur\',\'commercial\',\'ouvrier\'))');
        DB::statement('ALTER TABLE app_versions ADD CONSTRAINT app_versions_platform_check CHECK (platform IN (\'android\',\'ios\'))');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('app_versions');
    }
};
