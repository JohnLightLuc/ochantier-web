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
        Schema::create('admin_permissions', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->string('code', 80)->unique()->comment('ex. workers.validate, payouts.approve');
            $table->string('module', 40);
            $table->string('action', 20);
            $table->string('label', 160);
            $table->timestampTz('created_at')->useCurrent();
        });

        DB::statement('ALTER TABLE admin_permissions ADD CONSTRAINT admin_permissions_action_check CHECK (action IN (\'read\',\'create\',\'update\',\'validate\',\'approve\',\'export\',\'delete\'))');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('admin_permissions');
    }
};
