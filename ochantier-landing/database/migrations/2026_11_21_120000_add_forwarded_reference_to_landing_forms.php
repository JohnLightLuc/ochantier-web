<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Les formulaires sont désormais transmis à l’API O’CHANTIER. Les demandes enregistrées avant ce changement le
     * sont une fois par la commande landing:transmettre, qui note ici la référence donnée par l’API.
     */
    public function up(): void
    {
        foreach (['contact_requests', 'worker_applications', 'newsletter_subscribers'] as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->string('reference_api', 30)->nullable();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        foreach (['contact_requests', 'worker_applications', 'newsletter_subscribers'] as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->dropColumn('reference_api');
            });
        }
    }
};
