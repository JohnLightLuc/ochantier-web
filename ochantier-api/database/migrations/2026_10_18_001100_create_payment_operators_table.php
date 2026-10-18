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
        Schema::create('payment_operators', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->string('code', 30)->unique()->comment('orange_money, mtn_momo, moov_money, wave…');
            $table->string('name', 80);
            $table->foreignUuid('country_id')->constrained('countries');
            $table->string('provider', 40)->comment('agrégateur / PSP utilisé');
            $table->string('number_pattern', 120)->nullable();
            $table->boolean('supports_collection')->default(true);
            $table->boolean('supports_payout')->default(true);
            $table->boolean('is_active')->default(true);
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();
            $table->comment('Opérateurs Mobile Money / prestataires de paiement');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payment_operators');
    }
};
