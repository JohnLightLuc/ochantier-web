<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Recherche d’effectifs d’un entrepreneur sur un chantier attribué (« Rechercher des ouvriers », « Réservation
     * d’ouvriers ») : besoins par métier (effectif, tarif journalier), période ; O’CHANTIER propose les ouvriers
     * disponibles (offre complète ou partielle). L’entrepreneur paie par Mobile Money l’acompte X et les frais de
     * service (sur le coût total des ouvriers trouvés), puis le solde en échéances hebdomadaires. Les ouvriers
     * retenus sont affectés à l’équipe de l’entrepreneur ; les manquants font l’objet d’une nouvelle recherche.
     */
    public function up(): void
    {
        Schema::create('staffing_requests', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->string('reference', 20)->unique();
            $table->foreignUuid('site_id')->constrained('sites');
            $table->foreignUuid('contractor_id')->constrained('contractors');
            $table->uuid('parent_request_id')->nullable()->comment('recherche des ouvriers manquants d’une offre partielle');
            $table->date('start_date');
            $table->date('end_date');
            $table->integer('days');
            $table->string('status', 15)->default('recherche');
            $table->decimal('deposit_percent', 5, 2);
            $table->decimal('service_fee_percent', 5, 2);
            $table->bigInteger('workers_amount')->default(0)->comment('coût total des ouvriers proposés (XOF)');
            $table->bigInteger('deposit_amount')->default(0);
            $table->bigInteger('service_fee_amount')->default(0);
            $table->timestampTz('offered_at')->nullable();
            $table->timestampTz('decided_at')->nullable();
            $table->timestampTz('paid_at')->nullable();
            $table->foreignUuid('deposit_transaction_id')->nullable()->constrained('payment_transactions');
            $table->foreignUuid('created_by_user_id')->constrained('users');
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();
            $table->comment('Recherches d’effectifs des entrepreneurs (acompte et solde payés par l’entrepreneur)');
        });

        Schema::table('staffing_requests', function (Blueprint $table) {
            $table->foreign('parent_request_id')->references('id')->on('staffing_requests');
        });

        DB::statement("ALTER TABLE staffing_requests ADD CONSTRAINT staffing_requests_status_check CHECK (status IN ('recherche','offre','payee','refusee','annulee'))");
        DB::statement('ALTER TABLE staffing_requests ADD CONSTRAINT staffing_requests_days_check CHECK (days > 0 AND end_date >= start_date)');
        DB::statement('CREATE SEQUENCE staffing_request_reference_seq START 1 OWNED BY staffing_requests.reference');

        Schema::create('staffing_request_lines', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('staffing_request_id')->constrained('staffing_requests')->cascadeOnDelete();
            $table->foreignUuid('trade_id')->constrained('trades');
            $table->integer('headcount');
            $table->bigInteger('daily_rate')->comment('tarif journalier proposé (XOF)');
            $table->unique(['staffing_request_id', 'trade_id']);
        });

        DB::statement('ALTER TABLE staffing_request_lines ADD CONSTRAINT staffing_request_lines_check CHECK (headcount > 0 AND daily_rate > 0)');

        Schema::create('staffing_request_workers', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('staffing_request_line_id')->constrained('staffing_request_lines')->cascadeOnDelete();
            $table->foreignUuid('worker_id')->constrained('workers');
            $table->string('status', 10)->default('propose');
            $table->foreignUuid('assignment_id')->nullable()->constrained('assignments');
            $table->timestampTz('created_at')->useCurrent();
            $table->unique(['staffing_request_line_id', 'worker_id']);
        });

        DB::statement("ALTER TABLE staffing_request_workers ADD CONSTRAINT staffing_request_workers_status_check CHECK (status IN ('propose','retenu','libere'))");

        Schema::create('staffing_installments', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('staffing_request_id')->constrained('staffing_requests')->cascadeOnDelete();
            $table->integer('sequence');
            $table->string('label', 60);
            $table->date('due_date');
            $table->bigInteger('amount');
            $table->string('status', 10)->default('a_payer');
            $table->foreignUuid('payment_transaction_id')->nullable()->constrained('payment_transactions');
            $table->timestampTz('paid_at')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();
            $table->unique(['staffing_request_id', 'sequence']);
            $table->comment('Solde d’une recherche d’effectifs, payé chaque semaine par l’entrepreneur');
        });

        DB::statement("ALTER TABLE staffing_installments ADD CONSTRAINT staffing_installments_status_check CHECK (status IN ('a_payer','payee','annulee'))");
        DB::statement('ALTER TABLE payment_transactions DROP CONSTRAINT payment_transactions_purpose_check');
        DB::statement("ALTER TABLE payment_transactions ADD CONSTRAINT payment_transactions_purpose_check CHECK (purpose IN ('acompte','echeance','reversement_ouvrier','gain_commercial','remboursement','abonnement','commande_devis','effectifs'))");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('staffing_installments');
        Schema::dropIfExists('staffing_request_workers');
        Schema::dropIfExists('staffing_request_lines');
        Schema::dropIfExists('staffing_requests');
        DB::statement("DELETE FROM payment_transactions WHERE purpose = 'effectifs'");
        DB::statement('ALTER TABLE payment_transactions DROP CONSTRAINT payment_transactions_purpose_check');
        DB::statement("ALTER TABLE payment_transactions ADD CONSTRAINT payment_transactions_purpose_check CHECK (purpose IN ('acompte','echeance','reversement_ouvrier','gain_commercial','remboursement','abonnement','commande_devis'))");
    }
};
