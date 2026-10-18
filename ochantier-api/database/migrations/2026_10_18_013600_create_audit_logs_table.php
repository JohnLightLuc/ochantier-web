<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Journal d'audit générique, en ajout seul, partitionné par mois.
     *
     * Le partitionnement n'est pas exprimable avec le Schema Builder : la table est créée en SQL.
     * Les partitions mensuelles s'ajoutent à côté de la partition par défaut.
     */
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE audit_logs (
                id              UUID        NOT NULL DEFAULT gen_random_uuid(),
                actor_type      VARCHAR(20) NOT NULL,
                actor_id        UUID,
                action          VARCHAR(60) NOT NULL,
                module          VARCHAR(40) NOT NULL,
                subject_type    VARCHAR(40),
                subject_id      UUID,
                before_values   JSONB,
                after_values    JSONB,
                ip_address      INET,
                user_agent      TEXT,
                device_id       VARCHAR(100),
                correlation_id  UUID,
                created_at      TIMESTAMPTZ NOT NULL DEFAULT now(),
                PRIMARY KEY (id, created_at)
            ) PARTITION BY RANGE (created_at)
            SQL);
        DB::statement('CREATE TABLE audit_logs_default PARTITION OF audit_logs DEFAULT');
        DB::statement('CREATE INDEX ix_audit_logs_subject ON audit_logs (subject_type, subject_id, created_at)');
        DB::statement('CREATE INDEX ix_audit_logs_actor ON audit_logs (actor_type, actor_id, created_at)');
        DB::statement("COMMENT ON TABLE audit_logs IS 'Journal d''audit générique, en ajout seul, partitionné par mois'");
        DB::statement("COMMENT ON COLUMN audit_logs.actor_type IS 'admin, user, systeme, webhook'");
        DB::statement("COMMENT ON COLUMN audit_logs.action IS 'create, update, validate, approve, export, login…'");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS audit_logs');
    }
};
