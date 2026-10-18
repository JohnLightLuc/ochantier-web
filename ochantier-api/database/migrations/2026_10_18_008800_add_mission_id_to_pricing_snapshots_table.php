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
        Schema::table('pricing_snapshots', function (Blueprint $table) {
            $table->foreignUuid('mission_id')->nullable()->comment('mission née de l\'instantané confirmé')->constrained('missions');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('pricing_snapshots', function (Blueprint $table) {
            $table->dropConstrainedForeignId('mission_id');
        });
    }
};
