<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * « Mon équipe » de l’entrepreneur : collaborateurs du compte invités par SMS ou e-mail, avec un rôle (chef de
     * chantier, technicien, comptable, observateur), dans la limite des places de la formule. L’invitation
     * (table invitations) est acceptée à la première connexion de l’invité.
     */
    public function up(): void
    {
        Schema::create('contractor_members', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('contractor_id')->constrained('contractors');
            $table->foreignUuid('user_id')->constrained('users');
            $table->string('role', 20);
            $table->string('status', 10)->default('invite');
            $table->foreignUuid('invited_by_user_id')->nullable()->constrained('users');
            $table->timestampTz('joined_at')->nullable();
            $table->timestampTz('revoked_at')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();
            $table->unique(['contractor_id', 'user_id']);
            $table->comment('Collaborateurs du compte d’un entrepreneur');
        });

        DB::statement("ALTER TABLE contractor_members ADD CONSTRAINT contractor_members_role_check CHECK (role IN ('chef_chantier','technicien','comptable','observateur'))");
        DB::statement("ALTER TABLE contractor_members ADD CONSTRAINT contractor_members_status_check CHECK (status IN ('invite','actif','revoque'))");
        DB::statement("CREATE UNIQUE INDEX ux_contractor_members_user ON contractor_members (user_id) WHERE status <> 'revoque'");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('contractor_members');
    }
};
