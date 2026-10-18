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
        Schema::create('worker_references', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('worker_id')->constrained('workers')->cascadeOnDelete();
            $table->string('project_name', 200);
            $table->string('role', 120)->nullable();
            $table->string('employer_name', 200)->nullable();
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->string('contact_name', 160)->nullable();
            $table->string('contact_phone', 20)->nullable();
            $table->boolean('contact_consent')->default(false);
            $table->timestampTz('verified_at')->nullable();
            $table->foreignUuid('verified_by_admin_id')->nullable()->constrained('admins');
            $table->timestampTz('created_at')->useCurrent();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('worker_references');
    }
};
