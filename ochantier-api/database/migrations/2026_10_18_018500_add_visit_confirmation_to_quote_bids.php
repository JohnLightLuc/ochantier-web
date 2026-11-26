<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Confirmation de la visite d’un devis : le professionnel la déclare faite, le promoteur la confirme ou la conteste
     * (sans réponse, elle est confirmée d’office), le Back Office tranche une contestation. Les frais de visite ne sont
     * dus qu’une fois la visite confirmée. Les visites déjà déclarées sont considérées comme confirmées.
     */
    public function up(): void
    {
        Schema::table('quote_bids', function (Blueprint $table) {
            $table->string('visit_confirmation', 20)->nullable()->comment('confirmee, automatique, contestee');
            $table->timestampTz('visit_confirmed_at')->nullable();
            $table->text('visit_contest_reason')->nullable()->comment('motif du promoteur qui conteste la visite');
            $table->timestampTz('visit_contested_at')->nullable();
            $table->foreignUuid('visit_decided_by_admin_id')->nullable()->constrained('admins')->comment('administrateur qui a tranché la contestation');
        });

        DB::statement("ALTER TABLE quote_bids ADD CONSTRAINT quote_bids_visit_confirmation_check CHECK (visit_confirmation IN ('confirmee','automatique','contestee'))");
        DB::statement("UPDATE quote_bids SET visit_confirmation = 'automatique', visit_confirmed_at = coalesce(visited_at, now()) WHERE visit_status = 'realisee'");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE quote_bids DROP CONSTRAINT quote_bids_visit_confirmation_check');

        Schema::table('quote_bids', function (Blueprint $table) {
            $table->dropConstrainedForeignId('visit_decided_by_admin_id');
            $table->dropColumn(['visit_confirmation', 'visit_confirmed_at', 'visit_contest_reason', 'visit_contested_at']);
        });
    }
};
