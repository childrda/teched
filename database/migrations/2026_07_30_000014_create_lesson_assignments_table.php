<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lesson_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_class_id')->constrained('school_classes')->cascadeOnDelete();
            $table->foreignId('lesson_id')->constrained('lessons')->cascadeOnDelete();
            // Exact compiled manifest pin — not "whatever is current now".
            $table->foreignId('lesson_version_id')->constrained('lesson_versions')->restrictOnDelete();
            $table->foreignId('assigned_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('available_at')->nullable();
            // Informational only in 4A — deliberately unenforced. Late-work
            // policy belongs with the teacher dashboard (4B).
            $table->timestamp('due_at')->nullable();
            // Reserved and unread in this phase. Do not store grading overrides
            // here — that would create a second source of grading truth beside
            // the pinned manifest.
            $table->json('settings')->nullable();
            $table->timestamps();

            $table->index(['school_class_id', 'lesson_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lesson_assignments');
    }
};
