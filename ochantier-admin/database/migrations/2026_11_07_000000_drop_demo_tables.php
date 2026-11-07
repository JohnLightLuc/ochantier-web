<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tables des données de démonstration : le Back Office lit tout dans l’API et ne garde que ses sessions, son cache et
     * ses files d’attente.
     *
     * @var list<string>
     */
    private const TABLES = [
        'promoters', 'promoter_documents', 'promoter_activities', 'workers', 'worker_documents', 'worker_experiences', 'worker_periods', 'worker_activities',
        'sales_agents', 'sales_agent_documents', 'sales_agent_periods', 'sales_agent_objectives', 'sales_agent_adjustments', 'sales_agent_rewards',
        'sales_agent_appreciations', 'sales_agent_field_activities', 'sales_agent_activities', 'sales_rankings', 'sites', 'site_documents', 'site_incidents',
        'site_activities', 'needs', 'need_lines', 'need_offers', 'need_assignments', 'need_documents', 'need_activities', 'matching_cycles', 'matching_selections',
        'matching_rejections', 'matching_derogations', 'matching_recalculations', 'matching_notifications', 'matching_audits', 'promoter_offers',
        'promoter_offer_profiles', 'promoter_offer_activities', 'attendances', 'attendance_anomalies', 'attendance_corrections', 'attendance_activities', 'payments',
        'payment_items', 'payment_refunds', 'payment_activities', 'payout_batches', 'payment_notifications', 'subscription_plans', 'subscriptions',
        'subscription_users', 'subscription_invoices', 'subscription_activities', 'finance_archives', 'finance_closings', 'finance_views', 'finance_reports',
        'finance_audits', 'finance_resolutions', 'reviews', 'disputes', 'claims', 'quality_activities', 'quality_notifications', 'analytics_reports',
        'analytics_schedules', 'settings', 'setting_changes', 'integrations',
    ];

    public function up(): void
    {
        Schema::disableForeignKeyConstraints();

        foreach (self::TABLES as $table) {
            Schema::dropIfExists($table);
        }

        Schema::enableForeignKeyConstraints();

        // Colonnes ajoutées à `users` pour l’ancienne gestion locale des comptes.
        foreach (['phone', 'role', 'status', 'modules', 'two_factor', 'last_login_at', 'invitation_expires_on'] as $column) {
            if (Schema::hasColumn('users', $column)) {
                Schema::table('users', fn ($table) => $table->dropColumn($column));
            }
        }

        DB::table('users')->delete();
    }

    /**
     * Sans retour : ces données de démonstration n’existent plus.
     */
    public function down(): void {}
};
