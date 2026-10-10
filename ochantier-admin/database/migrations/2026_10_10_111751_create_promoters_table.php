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
        Schema::create('promoters', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('name');
            $table->string('legal_form');
            $table->string('rccm');
            $table->string('ncc')->nullable();
            $table->string('sector')->nullable();
            $table->string('head_office');
            $table->string('representative');
            $table->string('representative_role')->nullable();
            $table->string('phone');
            $table->string('email');
            $table->string('account_type');
            $table->string('location');
            $table->string('status')->index();
            $table->string('payment_status');
            $table->text('note')->nullable();
            $table->text('suspension_reason')->nullable();
            $table->date('suspended_at')->nullable();
            $table->date('registered_at');
            $table->dateTime('last_active_at')->nullable();
            // Chantiers, offres, paiements, avis et litiges de démonstration, en attendant les modules dédiés.
            $table->json('demo_relations')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('promoters');
    }
};
