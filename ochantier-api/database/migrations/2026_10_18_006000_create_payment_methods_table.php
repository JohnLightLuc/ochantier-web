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
        Schema::create('payment_methods', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->string('owner_type', 30)->comment('worker, promoter_profile, organization, commercial_profile');
            $table->uuid('owner_id');
            $table->string('method_type', 15)->default('mobile_money');
            $table->foreignUuid('operator_id')->nullable()->constrained('payment_operators');
            $table->string('account_holder_name', 160);
            $table->text('account_number_encrypted');
            $table->string('account_number_masked', 30);
            $table->boolean('is_default')->default(false);
            $table->timestampTz('verified_at')->nullable();
            $table->string('verification_method', 30)->nullable()->comment('otp, test_transfert, controle_manuel');
            $table->string('status', 15)->default('actif');
            $table->string('created_by_type', 20)->nullable();
            $table->uuid('created_by_id')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();
            $table->timestampTz('deleted_at')->nullable();
            $table->comment('Moyens de paiement (ouvriers, promoteurs, organisations, commerciaux)');
        });

        DB::statement('ALTER TABLE payment_methods ADD CONSTRAINT payment_methods_method_type_check CHECK (method_type IN (\'mobile_money\',\'virement\',\'carte\'))');
        DB::statement('ALTER TABLE payment_methods ADD CONSTRAINT payment_methods_status_check CHECK (status IN (\'actif\',\'desactive\'))');
        DB::statement('CREATE UNIQUE INDEX uq_payment_methods_default ON payment_methods (owner_type, owner_id) WHERE is_default AND deleted_at IS NULL');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payment_methods');
    }
};
