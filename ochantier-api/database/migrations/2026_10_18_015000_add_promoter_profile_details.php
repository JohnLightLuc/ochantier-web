<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Profil promoteur (« Modifier le profil ») : fonction du promoteur dans son entreprise, quartier et position GPS
     * du siège social.
     */
    public function up(): void
    {
        Schema::table('promoter_profiles', function (Blueprint $table) {
            $table->string('job_title', 120)->nullable()->comment('fonction dans l’entreprise, ex. Directeur général');
        });

        Schema::table('organizations', function (Blueprint $table) {
            $table->foreignUuid('neighborhood_id')->nullable()->constrained('neighborhoods');
            $table->geography('location', subtype: 'point', srid: 4326)->nullable()->comment('position GPS du siège social');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('neighborhood_id');
            $table->dropColumn('location');
        });

        Schema::table('promoter_profiles', function (Blueprint $table) {
            $table->dropColumn('job_title');
        });
    }
};
