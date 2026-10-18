<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $table) {
            $table->string('key', 100)->primary();
            $table->jsonb('value');
            $table->text('description')->nullable();
            $table->foreignUuid('updated_by_admin_id')->nullable()->constrained('admins');
            $table->timestampTz('updated_at')->useCurrent();
            $table->comment('Paramètres applicatifs (délais, seuils de verrouillage, arrondis, sessions…)');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('settings');
    }
};
