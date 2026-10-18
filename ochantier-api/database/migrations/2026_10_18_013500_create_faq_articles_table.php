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
        Schema::create('faq_articles', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->string('section', 80);
            $table->string('audience', 20);
            $table->string('title', 200);
            $table->text('content');
            $table->string('context_key', 80)->nullable()->comment('aide contextuelle liée à un écran');
            $table->integer('version')->default(1);
            $table->boolean('is_published')->default(false);
            $table->timestampTz('published_at')->nullable();
            $table->foreignUuid('updated_by_admin_id')->nullable()->constrained('admins');
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();
        });

        DB::statement('ALTER TABLE faq_articles ADD CONSTRAINT faq_articles_audience_check CHECK (audience IN (\'promoter\',\'commercial\',\'worker\',\'all\'))');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('faq_articles');
    }
};
