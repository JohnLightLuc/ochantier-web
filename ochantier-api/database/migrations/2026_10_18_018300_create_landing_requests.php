<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Demandes reçues par le site public O’CHANTIER : candidatures « Devenir ouvrier » (avec pièce d’identité et
     * photo), messages du formulaire de contact et inscriptions à la lettre d’information. Le site les transmet à
     * l’API ; le Back Office les traite.
     */
    public function up(): void
    {
        Schema::create('landing_worker_applications', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->string('reference', 20)->unique();
            $table->string('first_name', 80);
            $table->string('last_name', 80);
            $table->string('phone', 30);
            $table->string('phone_digits', 20)->index()->comment('chiffres du téléphone, pour repérer les doublons');
            $table->string('email', 190)->nullable();
            $table->date('birth_date')->nullable();
            $table->string('gender', 10)->nullable();
            $table->foreignUuid('photo_media_id')->nullable()->constrained('media_files');
            $table->foreignUuid('identity_media_id')->constrained('media_files');
            $table->string('trade_label', 60);
            $table->foreignUuid('trade_id')->nullable()->constrained('trades')->comment('métier du référentiel reconnu d’après le libellé');
            $table->integer('daily_rate')->nullable();
            $table->integer('experience_years');
            $table->string('level', 30);
            $table->jsonb('skills')->default('[]');
            $table->jsonb('certifications')->default('[]');
            $table->text('bio')->nullable();
            $table->string('commune_label', 80);
            $table->foreignUuid('commune_id')->nullable()->constrained('communes')->comment('commune du référentiel reconnue d’après le libellé');
            $table->string('neighborhood', 120)->nullable();
            $table->string('mobility', 40);
            $table->string('availability', 30);
            $table->jsonb('available_days')->default('[]');
            $table->string('payout_method', 30);
            $table->string('payout_number', 30)->nullable();
            $table->string('target_site', 120)->nullable();
            $table->string('target_site_commune', 80)->nullable();
            $table->string('status', 20)->default('nouvelle');
            $table->text('refusal_reason')->nullable();
            $table->foreignUuid('worker_id')->nullable()->constrained('workers');
            $table->foreignUuid('handled_by_admin_id')->nullable()->constrained('admins');
            $table->timestampTz('consented_at');
            $table->timestampsTz();
            $table->index(['status', 'created_at']);
            $table->comment('Candidatures « Devenir ouvrier » reçues par le site public');
        });
        DB::statement("ALTER TABLE landing_worker_applications ADD CONSTRAINT landing_worker_applications_status_check CHECK (status IN ('nouvelle','en_verification','convertie','refusee'))");
        DB::statement('CREATE SEQUENCE landing_application_reference_seq START 1 OWNED BY landing_worker_applications.reference');

        Schema::create('landing_contact_messages', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->string('reference', 20)->unique();
            $table->string('profile', 40);
            $table->string('name', 120);
            $table->string('company', 120)->nullable();
            $table->string('phone', 30);
            $table->string('email', 190);
            $table->string('subject', 120);
            $table->text('message');
            $table->string('status', 15)->default('nouveau');
            $table->foreignUuid('handled_by_admin_id')->nullable()->constrained('admins');
            $table->text('handling_note')->nullable();
            $table->timestampTz('handled_at')->nullable();
            $table->timestampTz('consented_at');
            $table->timestampsTz();
            $table->index(['status', 'created_at']);
            $table->comment('Messages du formulaire de contact du site public');
        });
        DB::statement("ALTER TABLE landing_contact_messages ADD CONSTRAINT landing_contact_messages_status_check CHECK (status IN ('nouveau','en_cours','traite'))");
        DB::statement('CREATE SEQUENCE landing_message_reference_seq START 1 OWNED BY landing_contact_messages.reference');

        Schema::create('newsletter_subscribers', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->string('email', 190)->unique();
            $table->timestampTz('subscribed_at');
            $table->timestampTz('unsubscribed_at')->nullable();
            $table->timestampsTz();
            $table->comment('Inscrits à la lettre d’information du site public');
        });

        DB::table('settings')->insertOrIgnore([
            'key' => 'landing.contact_response_hours',
            'value' => json_encode(24),
            'description' => 'Délai de réponse annoncé aux messages du formulaire de contact du site (heures).',
            'updated_at' => now(),
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('settings')->where('key', 'landing.contact_response_hours')->delete();
        Schema::dropIfExists('newsletter_subscribers');
        Schema::dropIfExists('landing_contact_messages');
        Schema::dropIfExists('landing_worker_applications');
    }
};
