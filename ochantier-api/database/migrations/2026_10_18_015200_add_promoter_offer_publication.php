<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Publication d’un chantier depuis l’application Promoteur :
     * - sites.offer_draft : besoins saisis avant la publication (offre et besoins exigent des dates, absentes d’un
     *   brouillon) ; conservé ensuite comme saisie d’origine ;
     * - offers.payment_mode : paiement du solde d’une offre contractuelle ;
     * - need_requested_workers : ouvriers choisis directement par le promoteur, à contacter en priorité ;
     * - numérotation des offres (OF-2026-0001) et des besoins (BS-2026-0001).
     */
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->jsonb('offer_draft')->nullable()->comment('besoins saisis par le promoteur : type d’offre, métiers, ouvriers choisis, forfait');
        });

        Schema::table('offers', function (Blueprint $table) {
            $table->string('payment_mode', 20)->nullable()->comment('offre contractuelle : hebdomadaire, par_etape, fin_de_chantier');
        });

        DB::statement("ALTER TABLE offers ADD CONSTRAINT offers_payment_mode_check CHECK (payment_mode IN ('hebdomadaire','par_etape','fin_de_chantier'))");

        Schema::create('need_requested_workers', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('need_id')->constrained('needs')->cascadeOnDelete();
            $table->foreignUuid('worker_id')->constrained('workers');
            $table->bigInteger('promoter_daily_rate')->nullable()->comment('tarif affiché au promoteur lors du choix (avec Z)');
            $table->foreignUuid('requested_by_user_id')->constrained('users');
            $table->timestampTz('created_at')->useCurrent();
            $table->unique(['need_id', 'worker_id']);
            $table->comment('Ouvriers choisis directement par le promoteur pour un besoin');
        });

        DB::statement('CREATE SEQUENCE offer_reference_seq START 1 OWNED BY offers.reference');
        DB::statement('CREATE SEQUENCE need_reference_seq START 1 OWNED BY needs.reference');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('DROP SEQUENCE IF EXISTS need_reference_seq');
        DB::statement('DROP SEQUENCE IF EXISTS offer_reference_seq');
        Schema::dropIfExists('need_requested_workers');
        DB::statement('ALTER TABLE offers DROP CONSTRAINT IF EXISTS offers_payment_mode_check');

        Schema::table('offers', function (Blueprint $table) {
            $table->dropColumn('payment_mode');
        });

        Schema::table('sites', function (Blueprint $table) {
            $table->dropColumn('offer_draft');
        });
    }
};
