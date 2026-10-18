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
        Schema::create('document_types', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->string('code', 40)->unique();
            $table->string('label', 120);
            $table->string('audience', 30);
            $table->foreignUuid('trade_id')->nullable()->comment('pièce propre à un métier')->constrained('trades');
            $table->boolean('is_required')->default(false);
            $table->boolean('is_identity')->default(false)->comment('pièce d\'identité (KYC)');
            $table->boolean('has_back_side')->default(false);
            $table->boolean('requires_expiry')->default(false);
            $table->integer('validity_months')->nullable();
            $table->rawColumn('allowed_mime', 'text[]')->default(DB::raw('ARRAY[\'application/pdf\',\'image/jpeg\',\'image/png\']'));
            $table->integer('max_size_kb')->default(10240);
            $table->boolean('is_sensitive')->default(true);
            $table->boolean('is_active')->default(true);
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();
            $table->comment('Types de pièces : configure les documents requis par profil ou métier');
        });

        DB::statement('ALTER TABLE document_types ADD CONSTRAINT document_types_audience_check CHECK (audience IN (\'worker\',\'promoter\',\'organization\',\'commercial\',\'site\',\'offer\',\'need\', \'mission\',\'subscription\',\'dispute\',\'claim\',\'incident\',\'ticket\',\'other\'))');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('document_types');
    }
};
