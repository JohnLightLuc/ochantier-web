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
        Schema::create('sites', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('reference')->unique();
            $table->string('name');
            $table->foreignId('promoter_id')->nullable()->constrained()->nullOnDelete();
            $table->string('promoter_name');
            $table->string('commune');
            $table->string('city');
            $table->string('address')->nullable();
            $table->decimal('latitude', 8, 4)->nullable();
            $table->decimal('longitude', 8, 4)->nullable();
            $table->string('type');
            $table->string('category')->nullable();
            $table->string('manager')->nullable();
            $table->string('manager_phone')->nullable();
            $table->date('starts_on');
            $table->date('ends_on');
            $table->unsignedInteger('planned_days');
            $table->unsignedTinyInteger('progress')->default(0);
            $table->unsignedInteger('required_workers');
            $table->unsignedInteger('assigned_workers')->default(0);
            $table->unsignedInteger('present_workers')->default(0);
            $table->string('status')->index();
            $table->string('priority');
            $table->text('description')->nullable();
            $table->string('working_hours')->nullable();
            $table->string('working_days')->nullable();
            $table->text('instructions')->nullable();
            $table->text('access_conditions')->nullable();
            $table->string('safety_equipment')->nullable();
            $table->unsignedBigInteger('budget')->default(0);
            $table->unsignedBigInteger('engaged_amount')->default(0);
            $table->unsignedBigInteger('paid_amount')->default(0);
            $table->unsignedTinyInteger('photos')->default(0);
            $table->text('correction_request')->nullable();
            $table->date('submitted_on');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sites');
    }
};
