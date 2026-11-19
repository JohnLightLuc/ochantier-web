<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Abonnement des entrepreneurs (« Abonnement et factures ») : les formules portent leur destinataire (promoteur ou
     * entrepreneur) et un abonnement appartient soit à un promoteur, soit à un entrepreneur. Les factures et le
     * Back Office restent communs.
     */
    public function up(): void
    {
        Schema::table('subscription_plans', function (Blueprint $table) {
            $table->string('audience', 15)->default('promoter')->comment('promoter ou contractor');
        });

        DB::statement("ALTER TABLE subscription_plans ADD CONSTRAINT subscription_plans_audience_check CHECK (audience IN ('promoter','contractor'))");

        Schema::table('subscriptions', function (Blueprint $table) {
            $table->uuid('promoter_profile_id')->nullable()->change();
            $table->foreignUuid('contractor_id')->nullable()->after('promoter_profile_id')->constrained('contractors');
        });

        DB::statement('ALTER TABLE subscriptions ADD CONSTRAINT subscriptions_owner_check CHECK ((promoter_profile_id IS NULL) <> (contractor_id IS NULL))');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('DELETE FROM subscription_invoices WHERE subscription_id IN (SELECT id FROM subscriptions WHERE contractor_id IS NOT NULL)');
        DB::statement('DELETE FROM subscriptions WHERE contractor_id IS NOT NULL');
        DB::statement('ALTER TABLE subscriptions DROP CONSTRAINT subscriptions_owner_check');

        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('contractor_id');
            $table->uuid('promoter_profile_id')->nullable(false)->change();
        });

        DB::statement("DELETE FROM subscription_plans WHERE audience = 'contractor'");
        DB::statement('ALTER TABLE subscription_plans DROP CONSTRAINT subscription_plans_audience_check');

        Schema::table('subscription_plans', function (Blueprint $table) {
            $table->dropColumn('audience');
        });
    }
};
