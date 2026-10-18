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
        Schema::create('progress_declarations', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('mission_id')->constrained('missions');
            $table->decimal('percent', 5, 2);
            $table->text('comment')->nullable();
            $table->foreignUuid('declared_by_user_id')->constrained('users');
            $table->timestampTz('declared_at')->useCurrent();
            $table->string('status', 10)->default('declaree');
            $table->foreignUuid('verified_by_admin_id')->nullable()->constrained('admins');
            $table->timestampTz('verified_at')->nullable();
            $table->uuid('client_uuid')->nullable()->unique();
            $table->comment('Progression métier déclarée (distincte de la progression calendaire)');
        });

        DB::statement('ALTER TABLE progress_declarations ADD CONSTRAINT progress_declarations_percent_check CHECK (percent BETWEEN 0 AND 100)');
        DB::statement('ALTER TABLE progress_declarations ADD CONSTRAINT progress_declarations_status_check CHECK (status IN (\'declaree\',\'verifiee\',\'rejetee\'))');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('progress_declarations');
    }
};
