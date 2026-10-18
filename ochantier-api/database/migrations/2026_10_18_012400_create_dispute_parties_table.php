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
        Schema::create('dispute_parties', function (Blueprint $table) {
            $table->foreignUuid('dispute_id')->constrained('disputes')->cascadeOnDelete();
            $table->string('party_type', 30);
            $table->uuid('party_id');
            $table->string('role', 15);
            $table->primary(['dispute_id', 'party_type', 'party_id']);
        });

        DB::statement('ALTER TABLE dispute_parties ADD CONSTRAINT dispute_parties_role_check CHECK (role IN (\'demandeur\',\'defendeur\',\'tiers\'))');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('dispute_parties');
    }
};
