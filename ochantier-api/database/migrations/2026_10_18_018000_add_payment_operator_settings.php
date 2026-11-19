<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Opérateurs de paiement réglables depuis le Back Office : montants minimal et maximal d’un encaissement,
     * ordre d’affichage et message montré aux payeurs (maintenance, délai inhabituel).
     */
    public function up(): void
    {
        Schema::table('payment_operators', function (Blueprint $table) {
            $table->bigInteger('min_amount')->nullable()->comment('montant minimal d’un encaissement (XOF), frais non compris');
            $table->bigInteger('max_amount')->nullable()->comment('montant maximal d’un encaissement (XOF), frais non compris');
            $table->integer('sort_order')->default(0);
            $table->string('notice', 200)->nullable()->comment('message affiché aux payeurs');
        });

        DB::statement('ALTER TABLE payment_operators ADD CONSTRAINT payment_operators_amounts_check CHECK ((min_amount IS NULL OR min_amount >= 0) AND (max_amount IS NULL OR max_amount > 0) AND (min_amount IS NULL OR max_amount IS NULL OR min_amount <= max_amount))');
        DB::statement('ALTER TABLE payment_operators ADD CONSTRAINT payment_operators_fee_check CHECK (collection_fee_percent >= 0 AND collection_fee_percent <= 20)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('ALTER TABLE payment_operators DROP CONSTRAINT payment_operators_fee_check');
        DB::statement('ALTER TABLE payment_operators DROP CONSTRAINT payment_operators_amounts_check');

        Schema::table('payment_operators', function (Blueprint $table) {
            $table->dropColumn(['min_amount', 'max_amount', 'sort_order', 'notice']);
        });
    }
};
