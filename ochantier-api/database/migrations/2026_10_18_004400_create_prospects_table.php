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
        Schema::create('prospects', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('commercial_id')->constrained('commercial_profiles');
            $table->string('first_name', 80);
            $table->string('last_name', 80)->nullable();
            $table->string('phone', 20);
            $table->foreignUuid('presumed_trade_id')->nullable()->constrained('trades');
            $table->foreignUuid('commune_id')->nullable()->constrained('communes');
            $table->foreignUuid('neighborhood_id')->nullable()->constrained('neighborhoods');
            $table->smallInteger('estimated_experience_years')->nullable();
            $table->string('interest_level', 10)->nullable();
            $table->boolean('has_documents')->nullable();
            $table->text('availability_note')->nullable();
            $table->string('status', 20)->default('nouveau');
            $table->timestampTz('contact_consent_at')->nullable();
            $table->foreignUuid('abandon_reason_id')->nullable()->constrained('reason_codes');
            $table->text('abandon_comment')->nullable();
            $table->uuid('converted_worker_id')->nullable()->comment('FK ajoutée après la création de workers');
            $table->timestampTz('converted_at')->nullable();
            $table->uuid('client_uuid')->nullable()->unique();
            $table->integer('lock_version')->default(1);
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();
            $table->timestampTz('deleted_at')->nullable();
            $table->index(['phone'], 'ix_prospects_phone');
        });

        DB::statement('ALTER TABLE prospects ADD CONSTRAINT prospects_estimated_experience_years_check CHECK (estimated_experience_years >= 0)');
        DB::statement('ALTER TABLE prospects ADD CONSTRAINT prospects_interest_level_check CHECK (interest_level IN (\'faible\',\'moyen\',\'fort\'))');
        DB::statement('ALTER TABLE prospects ADD CONSTRAINT prospects_status_check CHECK (status IN (\'nouveau\',\'a_contacter\',\'rendez_vous\',\'a_completer\',\'converti\',\'abandonne\'))');
        DB::statement('CREATE INDEX ix_prospects_commercial ON prospects (commercial_id, status) WHERE deleted_at IS NULL');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('prospects');
    }
};
