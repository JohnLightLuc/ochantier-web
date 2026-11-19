<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Assistance de l’application Entrepreneurs et Techniciens : questions fréquentes propres aux entrepreneurs et
     * référence des demandes « Nous écrire » (tickets).
     */
    public function up(): void
    {
        DB::statement('ALTER TABLE faq_articles DROP CONSTRAINT faq_articles_audience_check');
        DB::statement("ALTER TABLE faq_articles ADD CONSTRAINT faq_articles_audience_check CHECK (audience IN ('promoter','commercial','worker','contractor','all'))");
        DB::statement('CREATE SEQUENCE IF NOT EXISTS ticket_reference_seq START 1');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('DROP SEQUENCE IF EXISTS ticket_reference_seq');
        DB::statement("DELETE FROM faq_articles WHERE audience = 'contractor'");
        DB::statement('ALTER TABLE faq_articles DROP CONSTRAINT faq_articles_audience_check');
        DB::statement("ALTER TABLE faq_articles ADD CONSTRAINT faq_articles_audience_check CHECK (audience IN ('promoter','commercial','worker','all'))");
    }
};
