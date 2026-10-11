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
        Schema::create('promoter_offers', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('reference')->unique();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->string('trade');
            $table->string('specialty')->nullable();
            $table->string('urgency');
            $table->date('sent_on');
            $table->date('starts_on');
            $table->date('ends_on');
            $table->unsignedInteger('daily_cost');
            $table->text('message')->nullable();
            $table->boolean('cancelled')->default(false);
            $table->date('notified_on')->nullable();
            $table->string('payment_reference')->nullable();
            $table->timestamps();
        });

        Schema::create('promoter_offer_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('promoter_offer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('worker_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('trade');
            $table->string('commune')->nullable();
            $table->unsignedTinyInteger('experience')->default(0);
            $table->decimal('rating', 2, 1)->default(0);
            $table->unsignedInteger('daily_cost');
            $table->string('availability');
            $table->string('status');
            $table->string('source');
            $table->date('contacted_on')->nullable();
            $table->string('channel')->nullable();
            $table->text('reason')->nullable();
            $table->string('assignment_reference')->nullable();
            $table->string('replaced_by')->nullable();
            $table->string('replaces')->nullable();
            $table->timestamps();
        });

        Schema::create('promoter_offer_activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('promoter_offer_id')->constrained()->cascadeOnDelete();
            $table->dateTime('occurred_at')->index();
            $table->string('author');
            $table->string('category');
            $table->string('action');
            $table->string('old_value')->nullable();
            $table->string('new_value')->nullable();
            $table->text('comment')->nullable();
            $table->string('origin');
            $table->timestamps();
        });

        Schema::table('need_assignments', function (Blueprint $table) {
            $table->date('contacted_on')->nullable();
            $table->string('channel')->nullable();
            $table->text('reason')->nullable();
            $table->string('replaced_by')->nullable();
            $table->string('replaces')->nullable();
        });

        Schema::table('needs', function (Blueprint $table) {
            $table->string('parent_reference')->nullable()->index();
            $table->date('notified_on')->nullable();
            $table->string('payment_reference')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('needs', function (Blueprint $table) {
            $table->dropIndex(['parent_reference']);
            $table->dropColumn(['parent_reference', 'notified_on', 'payment_reference']);
        });
        Schema::table('need_assignments', function (Blueprint $table) {
            $table->dropColumn(['contacted_on', 'channel', 'reason', 'replaced_by', 'replaces']);
        });
        Schema::dropIfExists('promoter_offer_activities');
        Schema::dropIfExists('promoter_offer_profiles');
        Schema::dropIfExists('promoter_offers');
    }
};
