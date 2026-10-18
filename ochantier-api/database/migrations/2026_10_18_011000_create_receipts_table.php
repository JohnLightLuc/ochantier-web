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
        Schema::create('receipts', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->string('number', 30)->unique();
            $table->string('receipt_type', 15);
            $table->foreignUuid('transaction_id')->nullable()->constrained('payment_transactions');
            $table->string('subject_type', 30)->nullable();
            $table->uuid('subject_id')->nullable();
            $table->string('issued_to_type', 30);
            $table->uuid('issued_to_id');
            $table->bigInteger('amount');
            $table->foreignUuid('media_file_id')->nullable()->constrained('media_files');
            $table->timestampTz('issued_at')->useCurrent();
            $table->comment('Reçus, factures, relevés (numérotation unique)');
        });

        DB::statement('ALTER TABLE receipts ADD CONSTRAINT receipts_receipt_type_check CHECK (receipt_type IN (\'recu\',\'facture\',\'releve_gains\',\'avoir\'))');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('receipts');
    }
};
