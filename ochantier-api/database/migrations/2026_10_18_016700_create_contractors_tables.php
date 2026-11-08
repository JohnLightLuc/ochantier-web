<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Offre contractuelle : entrepreneurs (entreprise constituée ou indépendant, comptes de l’application Ouvriers ;
     * les simples ouvriers ne reçoivent pas ces offres) et leurs offres sur le forfait du promoteur.
     *
     * À la confirmation (acompte payé), le forfait est porté par un besoin « Forfait entrepreneur » et sa mission, qui
     * reçoivent l’instantané tarifaire et l’échéancier comme une réservation d’ouvriers ; le métier technique
     * forfait_entrepreneur, inactif, n’apparaît dans aucune liste.
     */
    public function up(): void
    {
        Schema::create('contractors', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->string('reference', 20)->unique();
            $table->string('kind', 15)->comment('entreprise (professionnel constitué) ou independant (particulier)');
            $table->string('name', 200)->comment('raison sociale ou nom de l’indépendant');
            $table->string('contact_name', 200)->nullable();
            $table->foreignUuid('user_id')->nullable()->unique()->comment('compte de l’application Ouvriers')->constrained('users');
            $table->string('dial_code', 5)->nullable();
            $table->string('phone_number', 20)->nullable();
            $table->string('email', 255)->nullable();
            $table->foreignUuid('commune_id')->nullable()->constrained('communes');
            $table->geography('location', subtype: 'point', srid: 4326)->nullable();
            $table->integer('team_size')->nullable()->comment('taille habituelle de l’équipe');
            $table->integer('completed_sites_count')->default(0);
            $table->decimal('rating_avg', 3, 2)->nullable();
            $table->integer('rating_count')->default(0);
            $table->foreignUuid('logo_media_id')->nullable()->constrained('media_files');
            $table->string('status', 15)->default('actif');
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();
            $table->comment('Entrepreneurs qui répondent aux offres contractuelles');
        });

        DB::statement("ALTER TABLE contractors ADD CONSTRAINT contractors_kind_check CHECK (kind IN ('entreprise','independant'))");
        DB::statement("ALTER TABLE contractors ADD CONSTRAINT contractors_status_check CHECK (status IN ('actif','suspendu','exclu'))");
        DB::statement('ALTER TABLE contractors ADD CONSTRAINT contractors_rating_avg_check CHECK (rating_avg BETWEEN 1 AND 5)');
        DB::statement('CREATE INDEX ix_contractors_location ON contractors USING GIST (location)');
        DB::statement('CREATE SEQUENCE contractor_reference_seq START 1 OWNED BY contractors.reference');

        Schema::create('contractor_bids', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('offer_id')->constrained('offers');
            $table->foreignUuid('contractor_id')->constrained('contractors');
            $table->bigInteger('amount')->comment('montant de l’entrepreneur, hors commission (XOF)');
            $table->integer('duration_days');
            $table->integer('team_size');
            $table->decimal('distance_km', 6, 1)->nullable();
            $table->text('message')->nullable();
            $table->string('status', 15)->default('proposee');
            $table->timestampTz('chosen_at')->nullable();
            $table->foreignUuid('chosen_by_user_id')->nullable()->constrained('users');
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();
            $table->unique(['offer_id', 'contractor_id']);
            $table->comment('Offres des entrepreneurs sur une offre contractuelle');
        });

        DB::statement("ALTER TABLE contractor_bids ADD CONSTRAINT contractor_bids_status_check CHECK (status IN ('proposee','choisie','retenue','non_retenue','annulee'))");
        DB::statement('ALTER TABLE contractor_bids ADD CONSTRAINT contractor_bids_amount_check CHECK (amount > 0)');
        DB::statement('ALTER TABLE contractor_bids ADD CONSTRAINT contractor_bids_duration_days_check CHECK (duration_days > 0)');
        DB::statement('ALTER TABLE contractor_bids ADD CONSTRAINT contractor_bids_team_size_check CHECK (team_size > 0)');
        DB::statement("CREATE UNIQUE INDEX ux_contractor_bids_chosen ON contractor_bids (offer_id) WHERE status IN ('choisie','retenue')");

        Schema::table('offers', function (Blueprint $table) {
            $table->foreignUuid('contractor_bid_id')->nullable()->comment('offre d’entrepreneur choisie')->constrained('contractor_bids');
        });

        DB::statement("INSERT INTO trades (code, label, description, sort_order, is_active, accepts_quotes) VALUES ('forfait_entrepreneur', 'Forfait entrepreneur', 'Besoin technique portant le forfait d’une offre contractuelle (non proposé aux promoteurs ni aux ouvriers).', 9990, false, false) ON CONFLICT (code) DO NOTHING");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('offers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('contractor_bid_id');
        });
        Schema::dropIfExists('contractor_bids');
        Schema::dropIfExists('contractors');
    }
};
