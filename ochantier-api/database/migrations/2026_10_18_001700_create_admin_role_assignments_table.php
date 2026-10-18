<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('admin_role_assignments', function (Blueprint $table) {
            $table->foreignUuid('admin_id')->constrained('admins')->cascadeOnDelete();
            $table->foreignUuid('role_id')->constrained('admin_roles');
            $table->foreignUuid('granted_by_admin_id')->nullable()->constrained('admins');
            $table->timestampTz('granted_at')->useCurrent();
            $table->primary(['admin_id', 'role_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('admin_role_assignments');
    }
};
