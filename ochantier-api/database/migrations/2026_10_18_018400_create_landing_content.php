<?php

use App\Support\Landing\LandingContentSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Contenu du site public modifiable depuis le Back Office : un brouillon unique et les versions publiées (la
     * dernière est en ligne). La version 1 reprend les textes actuels du site.
     */
    public function up(): void
    {
        Schema::create('landing_content_versions', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->integer('number')->unique();
            $table->jsonb('content');
            $table->jsonb('changed_sections')->default('[]');
            $table->text('note')->nullable();
            $table->integer('restored_from')->nullable()->comment('numéro de la version restaurée avant cette publication');
            $table->foreignUuid('published_by_admin_id')->nullable()->constrained('admins');
            $table->timestampTz('published_at');
        });

        Schema::create('landing_content_drafts', function (Blueprint $table) {
            $table->smallInteger('id')->primary();
            $table->jsonb('content');
            $table->integer('restored_from')->nullable();
            $table->foreignUuid('updated_by_admin_id')->nullable()->constrained('admins');
            $table->timestampTz('updated_at')->nullable();
        });
        DB::statement('ALTER TABLE landing_content_drafts ADD CONSTRAINT landing_content_drafts_single CHECK (id = 1)');

        $content = json_encode(LandingContentSchema::defaults(), JSON_UNESCAPED_UNICODE);
        DB::table('landing_content_versions')->insert(['number' => 1, 'content' => $content, 'note' => 'Version initiale : textes du site.', 'published_at' => now()]);
        DB::table('landing_content_drafts')->insert(['id' => 1, 'content' => $content]);
    }

    public function down(): void
    {
        Schema::dropIfExists('landing_content_drafts');
        Schema::dropIfExists('landing_content_versions');
    }
};
