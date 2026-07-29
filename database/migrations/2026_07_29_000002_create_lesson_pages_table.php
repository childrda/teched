<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lesson_pages', function (Blueprint $table) {
            $table->id();
            // Stable, globally unique student-facing identifier (ULID).
            $table->string('page_id', 26)->unique();
            $table->foreignId('lesson_id')->constrained('lessons')->cascadeOnDelete();
            $table->string('title');
            $table->unsignedInteger('position');
            $table->string('completion_type')->default('view');
            $table->unsignedInteger('estimated_minutes')->nullable();
            $table->json('settings');
            $table->timestamps();

            $table->unique(['lesson_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lesson_pages');
    }
};
