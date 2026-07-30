<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('block_submissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lesson_attempt_id')->constrained('lesson_attempts')->cascadeOnDelete();
            $table->foreignId('lesson_version_id')->constrained('lesson_versions')->cascadeOnDelete();
            $table->string('block_id', 26);
            $table->string('block_type');
            $table->unsignedInteger('attempt_number');
            $table->json('response');
            $table->json('grading_result')->nullable();
            $table->decimal('score', 8, 2)->nullable();
            $table->decimal('max_score', 8, 2)->nullable();
            $table->decimal('percentage', 8, 2)->nullable();
            $table->boolean('passed')->nullable();
            $table->boolean('requires_manual_review')->default(false);
            $table->unsignedInteger('active_seconds_at_submission')->nullable();
            // Explicit default so this stays stable if another NOT NULL
            // timestamp is ever added above it (MySQL's implicit ON UPDATE
            // only attaches to the first undeclared TIMESTAMP).
            $table->timestamp('submitted_at')->useCurrent();

            $table->unique(
                ['lesson_attempt_id', 'block_id', 'attempt_number'],
                'block_submissions_attempt_block_number'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('block_submissions');
    }
};
