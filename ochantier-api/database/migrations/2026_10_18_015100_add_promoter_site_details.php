<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Chantier saisi depuis l’application Promoteur (« Nouveau chantier ») : type de travaux, responsable du chantier
     * et numérotation des références lisibles (CH-2026-0001).
     */
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->string('work_type', 30)->nullable()->comment('construction_neuve, renovation, extension, finitions');
            $table->foreignUuid('responsible_user_id')->nullable()->comment('responsable du chantier : le promoteur ou un utilisateur de son entreprise')->constrained('users');
        });

        DB::statement("ALTER TABLE sites ADD CONSTRAINT sites_work_type_check CHECK (work_type IN ('construction_neuve','renovation','extension','finitions'))");
        DB::statement('CREATE SEQUENCE site_reference_seq START 1 OWNED BY sites.reference');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('DROP SEQUENCE IF EXISTS site_reference_seq');
        DB::statement('ALTER TABLE sites DROP CONSTRAINT IF EXISTS sites_work_type_check');

        Schema::table('sites', function (Blueprint $table) {
            $table->dropConstrainedForeignId('responsible_user_id');
            $table->dropColumn('work_type');
        });
    }
};
