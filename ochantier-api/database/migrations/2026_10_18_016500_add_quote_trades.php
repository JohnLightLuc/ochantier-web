<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Demande de devis du promoteur (« Avez-vous un devis ? » → Non) : corps de métier proposés au technicien.
     * Le manœuvre n’établit pas de devis ; l’étanchéiste rejoint le référentiel des métiers.
     */
    public function up(): void
    {
        Schema::table('trades', function (Blueprint $table) {
            $table->boolean('accepts_quotes')->default(true)->comment('proposé dans la demande de devis du promoteur');
        });

        DB::table('trades')->where('code', 'manoeuvre')->update(['accepts_quotes' => false]);
        DB::statement("INSERT INTO trades (code, label, description, sort_order) VALUES ('etancheiste', 'Étanchéiste', 'Étanchéité des toitures, terrasses et fondations.', 110) ON CONFLICT (code) DO NOTHING");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('trades', function (Blueprint $table) {
            $table->dropColumn('accepts_quotes');
        });
    }
};
