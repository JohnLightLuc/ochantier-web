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
        Schema::create('integrations', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->string('code', 40);
            $table->string('provider', 60);
            $table->string('service_type', 15);
            $table->string('environment', 12);
            $table->text('endpoint')->nullable();
            $table->string('client_id', 200)->nullable();
            $table->text('secret_encrypted')->nullable();
            $table->date('credentials_expire_at')->nullable();
            $table->string('status', 10)->default('inactive');
            $table->timestampTz('last_tested_at')->nullable();
            $table->boolean('last_test_ok')->nullable();
            $table->text('last_test_message')->nullable();
            $table->integer('latency_ms')->nullable();
            $table->decimal('error_rate', 5, 2)->nullable();
            $table->foreignUuid('updated_by_admin_id')->nullable()->constrained('admins');
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();
            $table->unique(['code', 'environment']);
            $table->comment('Connexions aux prestataires ; le secret est chiffré par l\'application, jamais stocké en clair');
        });

        DB::statement('ALTER TABLE integrations ADD CONSTRAINT integrations_service_type_check CHECK (service_type IN (\'paiement\',\'sms\',\'push\',\'email\',\'cartographie\',\'stockage\',\'autre\'))');
        DB::statement('ALTER TABLE integrations ADD CONSTRAINT integrations_environment_check CHECK (environment IN (\'bac_a_sable\',\'production\'))');
        DB::statement('ALTER TABLE integrations ADD CONSTRAINT integrations_status_check CHECK (status IN (\'active\',\'inactive\',\'en_erreur\'))');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('integrations');
    }
};
