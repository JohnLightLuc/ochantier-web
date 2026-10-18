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
        Schema::create('offers', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->string('reference', 20)->unique();
            $table->foreignUuid('site_id')->constrained('sites');
            $table->string('offer_type', 15);
            $table->string('price_origin', 10);
            $table->string('title', 200);
            $table->text('description')->nullable();
            $table->string('urgency', 10)->default('normale');
            $table->date('start_date');
            $table->date('end_date')->nullable();
            $table->bigInteger('contract_total_amount')->nullable()->comment('montant global proposé (XOF)');
            $table->foreignUuid('quote_document_id')->nullable()->comment('devis chargé par le promoteur, s\'il en a un')->constrained('documents');
            $table->bigInteger('quote_amount')->nullable();
            $table->text('deliverables')->nullable();
            $table->string('visibility', 10)->default('ciblee');
            $table->timestampTz('published_at')->nullable();
            $table->timestampTz('application_deadline')->nullable();
            $table->boolean('applications_open')->default(true);
            $table->smallInteger('extension_count')->default(0);
            $table->boolean('auto_renew')->default(false);
            $table->string('status', 15)->default('brouillon');
            $table->timestampTz('submitted_at')->nullable();
            $table->foreignUuid('assigned_admin_id')->nullable()->constrained('admins');
            $table->foreignUuid('created_by_user_id')->constrained('users');
            $table->text('cancellation_reason')->nullable();
            $table->uuid('client_uuid')->nullable()->unique();
            $table->integer('lock_version')->default(1);
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();
            $table->timestampTz('deleted_at')->nullable();
            $table->unique(['id', 'site_id', 'offer_type']);
            $table->index(['status', 'submitted_at'], 'ix_offers_queue');
            $table->comment('Offre promoteur publiée sur un chantier : un ou plusieurs besoins, prix fixé par l\'ouvrier ou le promoteur');
        });

        DB::statement('ALTER TABLE offers ADD CONSTRAINT offers_offer_type_check CHECK (offer_type IN (\'ponctuelle\',\'temporaire\',\'contractuelle\'))');
        DB::statement('ALTER TABLE offers ADD CONSTRAINT offers_price_origin_check CHECK (price_origin IN (\'ouvrier\',\'promoteur\'))');
        DB::statement('ALTER TABLE offers ADD CONSTRAINT offers_urgency_check CHECK (urgency IN (\'basse\',\'normale\',\'haute\',\'critique\'))');
        DB::statement('ALTER TABLE offers ADD CONSTRAINT offers_contract_total_amount_check CHECK (contract_total_amount > 0)');
        DB::statement('ALTER TABLE offers ADD CONSTRAINT offers_quote_amount_check CHECK (quote_amount > 0)');
        DB::statement('ALTER TABLE offers ADD CONSTRAINT offers_visibility_check CHECK (visibility IN (\'ciblee\',\'publique\'))');
        DB::statement('ALTER TABLE offers ADD CONSTRAINT offers_status_check CHECK (status IN (\'brouillon\',\'soumise\',\'en_controle\',\'a_corriger\',\'publiee\',\'suspendue\', \'pourvue\',\'cloturee\',\'annulee\'))');
        DB::statement('ALTER TABLE offers ADD CONSTRAINT offers_check1 CHECK (end_date IS NULL OR end_date >= start_date)');
        DB::statement('ALTER TABLE offers ADD CONSTRAINT offers_check2 CHECK ((offer_type = \'contractuelle\') = (price_origin = \'promoteur\'))');
        DB::statement('ALTER TABLE offers ADD CONSTRAINT offers_check3 CHECK (offer_type <> \'contractuelle\' OR status = \'brouillon\' OR contract_total_amount IS NOT NULL)');
        DB::statement('ALTER TABLE offers ADD CONSTRAINT offers_check4 CHECK (offer_type = \'contractuelle\' OR (contract_total_amount IS NULL AND quote_document_id IS NULL AND quote_amount IS NULL))');
        DB::statement('CREATE INDEX ix_offers_site ON offers (site_id) WHERE deleted_at IS NULL');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('offers');
    }
};
