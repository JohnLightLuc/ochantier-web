<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Application Entrepreneurs et Techniciens : comptes de type contractor (entreprise ou indépendant), reliés à
     * contractors.user_id une fois le profil choisi. Le type worker reste réservé à la future application des ouvriers.
     */
    public function up(): void
    {
        DB::statement('ALTER TABLE users DROP CONSTRAINT users_user_type_check');
        DB::statement("ALTER TABLE users ADD CONSTRAINT users_user_type_check CHECK (user_type IN ('promoter','commercial','worker','contractor'))");
        DB::statement("COMMENT ON COLUMN contractors.user_id IS 'compte de l’application Entrepreneurs et Techniciens (user_type contractor)'");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('ALTER TABLE users DROP CONSTRAINT users_user_type_check');
        DB::statement("ALTER TABLE users ADD CONSTRAINT users_user_type_check CHECK (user_type IN ('promoter','commercial','worker'))");
        DB::statement("COMMENT ON COLUMN contractors.user_id IS 'compte de l’application Ouvriers'");
    }
};
