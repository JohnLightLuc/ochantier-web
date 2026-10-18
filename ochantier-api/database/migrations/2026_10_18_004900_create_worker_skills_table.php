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
        Schema::create('worker_skills', function (Blueprint $table) {
            $table->foreignUuid('worker_id')->constrained('workers')->cascadeOnDelete();
            $table->foreignUuid('skill_id')->constrained('skills');
            $table->string('level', 15)->nullable();
            $table->text('comment')->nullable();
            $table->primary(['worker_id', 'skill_id']);
        });

        DB::statement('ALTER TABLE worker_skills ADD CONSTRAINT worker_skills_level_check CHECK (level IN (\'debutant\',\'intermediaire\',\'confirme\',\'expert\'))');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('worker_skills');
    }
};
