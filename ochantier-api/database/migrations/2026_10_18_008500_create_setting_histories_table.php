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
        Schema::create('setting_histories', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->string('setting_key', 100);
            $table->foreign('setting_key')->references('key')->on('settings');
            $table->jsonb('old_value')->nullable();
            $table->jsonb('new_value');
            $table->text('reason')->nullable();
            $table->foreignUuid('changed_by_admin_id')->constrained('admins');
            $table->timestampTz('created_at')->useCurrent();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('setting_histories');
    }
};
