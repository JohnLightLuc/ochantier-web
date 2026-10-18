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
        Schema::create('organization_members', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('organization_id')->constrained('organizations');
            $table->foreignUuid('user_id')->constrained('users');
            $table->string('role', 30);
            $table->string('status', 20)->default('invite');
            $table->foreignUuid('invited_by_user_id')->nullable()->constrained('users');
            $table->timestampTz('joined_at')->nullable();
            $table->timestampTz('revoked_at')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();
            $table->unique(['organization_id', 'user_id']);
        });

        DB::statement('ALTER TABLE organization_members ADD CONSTRAINT organization_members_role_check CHECK (role IN (\'administrateur\',\'chef_projet\',\'responsable_chantier\',\'responsable_financier\',\'lecteur\'))');
        DB::statement('ALTER TABLE organization_members ADD CONSTRAINT organization_members_status_check CHECK (status IN (\'invite\',\'actif\',\'suspendu\',\'revoque\'))');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('organization_members');
    }
};
