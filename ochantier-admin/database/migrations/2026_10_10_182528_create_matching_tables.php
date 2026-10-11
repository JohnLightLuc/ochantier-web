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
        Schema::create('matching_cycles', function (Blueprint $table) {
            $table->id();
            $table->string('reference')->unique();
            $table->dateTime('ran_at');
            $table->string('scope');
            $table->unsignedInteger('needs')->default(0);
            $table->unsignedInteger('workers')->default(0);
            $table->unsignedInteger('recommendations')->default(0);
            $table->unsignedInteger('selections')->default(0);
            $table->unsignedInteger('validations')->default(0);
            $table->unsignedInteger('assignments')->default(0);
            $table->unsignedInteger('successes')->default(0);
            $table->unsignedInteger('failures')->default(0);
            $table->unsignedInteger('without_profile')->default(0);
            $table->unsignedInteger('excluded')->default(0);
            $table->unsignedInteger('warnings')->default(0);
            $table->unsignedInteger('errors')->default(0);
            $table->unsignedInteger('duration')->default(0);
            $table->string('initiator');
            $table->string('status');
            $table->string('algorithm');
            $table->string('zone');
            $table->string('trade');
            $table->timestamps();
        });

        Schema::create('matching_selections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('need_offer_id')->unique()->constrained()->cascadeOnDelete();
            $table->json('main');
            $table->json('replacements');
            $table->string('status');
            $table->string('author');
            $table->date('updated_on');
            $table->text('note')->nullable();
            $table->timestamps();
        });

        Schema::create('matching_rejections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('need_offer_id')->constrained()->cascadeOnDelete();
            $table->string('candidate');
            $table->string('reason');
            $table->string('nature');
            $table->string('criterion')->nullable();
            $table->text('comment')->nullable();
            $table->string('corrective_action')->nullable();
            $table->string('author');
            $table->date('rejected_on');
            $table->timestamps();
            $table->unique(['need_offer_id', 'candidate']);
        });

        Schema::create('matching_derogations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('need_offer_id')->constrained()->cascadeOnDelete();
            $table->string('candidate');
            $table->text('justification');
            $table->string('author');
            $table->date('granted_on');
            $table->timestamps();
            $table->unique(['need_offer_id', 'candidate']);
        });

        Schema::create('matching_recalculations', function (Blueprint $table) {
            $table->id();
            $table->string('match_reference')->index();
            $table->dateTime('recalculated_at');
            $table->unsignedTinyInteger('old_score');
            $table->unsignedTinyInteger('new_score');
            $table->string('reason');
            $table->string('old_weights');
            $table->string('new_weights');
            $table->string('author');
            $table->string('algorithm');
            $table->timestamps();
        });

        Schema::create('matching_notifications', function (Blueprint $table) {
            $table->id();
            $table->string('type');
            $table->string('recipient');
            $table->string('channel');
            $table->dateTime('sent_at');
            $table->text('content');
            $table->string('action');
            $table->string('status');
            $table->timestamps();
        });

        Schema::create('matching_audits', function (Blueprint $table) {
            $table->id();
            $table->dateTime('occurred_at')->index();
            $table->string('author');
            $table->string('action');
            $table->string('subject')->nullable();
            $table->text('detail')->nullable();
            $table->timestamps();
        });

        Schema::table('need_offers', function (Blueprint $table) {
            $table->string('last_cycle')->nullable();
            $table->date('last_matched_on')->nullable();
            $table->string('last_matched_by')->nullable();
        });

        Schema::table('need_assignments', function (Blueprint $table) {
            $table->unsignedTinyInteger('score')->nullable();
            $table->text('warnings')->nullable();
            $table->string('match_reference')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('need_assignments', function (Blueprint $table) {
            $table->dropColumn(['score', 'warnings', 'match_reference']);
        });
        Schema::table('need_offers', function (Blueprint $table) {
            $table->dropColumn(['last_cycle', 'last_matched_on', 'last_matched_by']);
        });
        foreach (['matching_audits', 'matching_notifications', 'matching_recalculations', 'matching_derogations', 'matching_rejections', 'matching_selections', 'matching_cycles'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
