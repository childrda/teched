<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lesson_blocks', function (Blueprint $table) {
            $table->id();
            // Stable, globally unique student-facing identifier (ULID).
            $table->string('block_id', 26)->unique();
            $table->foreignId('lesson_page_id')->constrained('lesson_pages')->cascadeOnDelete();
            $table->string('type');
            $table->unsignedInteger('position');
            $table->json('config');
            $table->json('grading')->nullable();
            $table->timestamps();

            $table->unique(['lesson_page_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lesson_blocks');
    }
};
