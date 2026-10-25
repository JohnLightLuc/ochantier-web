<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * « Inviter un ami » (parrainage) de l’application Promoteur :
     * - promoter_profiles.referral_code : code parrain partagé dans le lien d’invitation ;
     * - promoter_profiles.referred_by_profile_id : parrain du promoteur inscrit avec ce code ;
     * - referrals : invitations envoyées par SMS ou inscriptions par le lien, « Envoyée » puis « Inscrit ».
     */
    public function up(): void
    {
        Schema::table('promoter_profiles', function (Blueprint $table) {
            $table->string('referral_code', 20)->nullable()->unique()->comment('code parrain');
            $table->foreignUuid('referred_by_profile_id')->nullable()->comment('parrain')->constrained('promoter_profiles');
        });

        Schema::create('referrals', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('referrer_profile_id')->constrained('promoter_profiles')->cascadeOnDelete();
            $table->string('channel', 10)->comment('sms, lien');
            $table->string('invited_phone', 20)->nullable()->comment('E.164');
            $table->string('invited_name', 160)->nullable();
            $table->foreignUuid('referred_user_id')->nullable()->constrained('users');
            $table->string('status', 10)->default('envoyee');
            $table->timestampTz('sent_at')->nullable();
            $table->timestampTz('registered_at')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();
            $table->index(['referrer_profile_id', 'created_at'], 'ix_referrals_referrer');
            $table->comment('Parrainage : invitation d’un proche à rejoindre O’CHANTIER');
        });

        DB::statement("ALTER TABLE referrals ADD CONSTRAINT referrals_channel_check CHECK (channel IN ('sms','lien'))");
        DB::statement("ALTER TABLE referrals ADD CONSTRAINT referrals_status_check CHECK (status IN ('envoyee','inscrite'))");
        DB::statement('CREATE UNIQUE INDEX uq_referrals_phone ON referrals (referrer_profile_id, invited_phone) WHERE invited_phone IS NOT NULL');
        DB::statement('CREATE UNIQUE INDEX uq_referrals_referred_user ON referrals (referred_user_id) WHERE referred_user_id IS NOT NULL');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('referrals');

        Schema::table('promoter_profiles', function (Blueprint $table) {
            $table->dropConstrainedForeignId('referred_by_profile_id');
            $table->dropUnique(['referral_code']);
            $table->dropColumn('referral_code');
        });
    }
};
