<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Codes de confirmation d’une nouvelle adresse e-mail (« Changer d’adresse e-mail » du commercial).
     */
    public function up(): void
    {
        DB::statement('ALTER TABLE otp_codes DROP CONSTRAINT otp_codes_purpose_check');
        DB::statement("ALTER TABLE otp_codes ADD CONSTRAINT otp_codes_purpose_check CHECK (purpose IN ('activation','login','password_reset','sensitive_action','phone_change','worker_phone_check','email_change'))");
    }

    public function down(): void
    {
        DB::statement("DELETE FROM otp_codes WHERE purpose = 'email_change'");
        DB::statement('ALTER TABLE otp_codes DROP CONSTRAINT otp_codes_purpose_check');
        DB::statement("ALTER TABLE otp_codes ADD CONSTRAINT otp_codes_purpose_check CHECK (purpose IN ('activation','login','password_reset','sensitive_action','phone_change','worker_phone_check'))");
    }
};
