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
        Schema::create('worker_responses', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('suggestion_id')->constrained('suggestions');
            $table->foreignUuid('contact_id')->nullable()->constrained('worker_offer_contacts');
            $table->foreignUuid('worker_id')->constrained('workers');
            $table->string('decision', 15);
            $table->foreignUuid('reason_id')->nullable()->constrained('reason_codes');
            $table->text('conditions')->nullable();
            $table->bigInteger('net_daily_rate_shown')->nullable();
            $table->timestampTz('responded_at')->useCurrent();
            $table->string('recorded_by_type', 20);
            $table->uuid('recorded_by_id');
            $table->foreignUuid('proof_media_id')->nullable()->constrained('media_files');
            $table->foreignUuid('validated_by_admin_id')->nullable()->comment('validation de l\'accord par le Back Office')->constrained('admins');
            $table->timestampTz('validated_at')->nullable();
            $table->uuid('client_uuid')->nullable()->unique();
            $table->timestampTz('created_at')->useCurrent();
            $table->index(['suggestion_id'], 'ix_worker_responses_suggestion');
            $table->comment('Accord ou refus recueilli (consentement : date, auteur, mission, tarif net, preuve)');
        });

        DB::statement('ALTER TABLE worker_responses ADD CONSTRAINT worker_responses_decision_check CHECK (decision IN (\'accepte\',\'refuse\',\'indisponible\'))');
        DB::statement('ALTER TABLE worker_responses ADD CONSTRAINT worker_responses_recorded_by_type_check CHECK (recorded_by_type IN (\'commercial\',\'admin\',\'worker\'))');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('worker_responses');
    }
};
