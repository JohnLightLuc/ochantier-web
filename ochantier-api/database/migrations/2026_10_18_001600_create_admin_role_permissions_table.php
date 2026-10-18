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
        Schema::create('admin_role_permissions', function (Blueprint $table) {
            $table->foreignUuid('role_id')->constrained('admin_roles')->cascadeOnDelete();
            $table->foreignUuid('permission_id')->constrained('admin_permissions')->cascadeOnDelete();
            $table->primary(['role_id', 'permission_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('admin_role_permissions');
    }
};
