<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Commission O’CHANTIER propre aux offres contractuelles (réglage contractor_offers.commission_percent), figée sur
     * chaque offre à sa publication. Le réglage et les offres existantes reprennent le taux Y en vigueur : rien ne
     * change pour les promoteurs tant que le taux n’est pas modifié.
     */
    public function up(): void
    {
        Schema::table('offers', function (Blueprint $table) {
            $table->decimal('commission_percent', 5, 2)->nullable()->after('contract_total_amount')->comment('commission O’CHANTIER (%) figée à la publication d’une offre contractuelle');
        });

        $y = DB::table('pricing_parameters')->where('code', 'Y')->where('scope', 'global')->where('status', 'actif')
            ->where('effective_from', '<=', now())->where(fn ($query) => $query->whereNull('effective_to')->orWhere('effective_to', '>', now()))
            ->orderByDesc('effective_from')->value('value') ?? 8;

        DB::table('offers')->where('offer_type', 'contractuelle')->update(['commission_percent' => $y]);

        DB::table('settings')->insertOrIgnore([
            'key' => 'contractor_offers.commission_percent',
            'value' => json_encode((int) round((float) $y)),
            'description' => 'Commission O’CHANTIER ajoutée au montant du professionnel sur une offre contractuelle (%), figée sur chaque offre à sa publication.',
            'updated_at' => now(),
        ]);
        DB::table('settings')->insertOrIgnore([
            'key' => 'pro.compared_offers',
            'value' => json_encode(3),
            'description' => 'Nombre d’offres de professionnels montrées au promoteur pour une demande de devis (par métier) ou une offre contractuelle.',
            'updated_at' => now(),
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('settings')->whereIn('key', ['contractor_offers.commission_percent', 'pro.compared_offers'])->delete();

        Schema::table('offers', function (Blueprint $table) {
            $table->dropColumn('commission_percent');
        });
    }
};
