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
        Schema::create('pricing_snapshots', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('need_id')->constrained('needs');
            $table->string('kind', 12);
            $table->string('offer_type', 15);
            $table->string('price_origin', 10);
            $table->decimal('x_rate', 7, 4);
            $table->decimal('y_rate', 7, 4);
            $table->decimal('z_rate', 7, 4);
            $table->decimal('r_rate', 7, 4);
            $table->jsonb('parameter_ids')->comment('lignes de pricing_parameters utilisées');
            $table->foreignUuid('subscription_id')->nullable()->comment('abonnement en vigueur au calcul')->constrained('subscriptions');
            $table->integer('headcount');
            $table->integer('planned_days')->nullable();
            $table->bigInteger('workers_base_amount')->comment('coût ouvriers (base)');
            $table->bigInteger('promoter_commission_amount')->comment('base × Y');
            $table->bigInteger('worker_commission_amount')->comment('total Z');
            $table->bigInteger('total_promoter_amount')->comment('base + commission promoteur');
            $table->bigInteger('deposit_amount')->comment('total × X');
            $table->bigInteger('balance_amount')->comment('total − acompte');
            $table->string('rounding_rule', 30)->default('arrondi_unite');
            $table->jsonb('lines')->default('[]')->comment('détail par ouvrier ou poste');
            $table->string('computed_by_type', 20)->nullable();
            $table->uuid('computed_by_id')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->comment('Instantané tarifaire : simulations, devis, puis valeurs figées à la confirmation');
        });

        DB::statement('ALTER TABLE pricing_snapshots ADD CONSTRAINT pricing_snapshots_kind_check CHECK (kind IN (\'simulation\',\'devis\',\'confirme\'))');
        DB::statement('CREATE INDEX ix_pricing_snapshots_need ON pricing_snapshots (need_id, created_at DESC)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pricing_snapshots');
    }
};
