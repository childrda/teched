<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lesson_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('lesson_id')->constrained('lessons')->cascadeOnDelete();
            $table->foreignId('lesson_version_id')->constrained('lesson_versions')->cascadeOnDelete();

            // Manifest page_id ULID — not a FK to lesson_pages (authoring may change).
            $table->string('current_page_id', 26);

            $table->string('status');
            // Explicit useCurrent() on every NOT NULL timestamp: without it,
            // MySQL gives only the first an implicit DEFAULT/ON UPDATE
            // CURRENT_TIMESTAMP, and later ones get '0000-00-00' (error 1067
            // under strict mode). An explicit default also suppresses the
            // automatic ON UPDATE clause so started_at is not rewritten on
            // every revision / activity bump.
            $table->timestamp('started_at')->useCurrent();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('last_activity_at')->useCurrent();
            $table->unsignedInteger('active_seconds')->default(0);
            $table->string('shuffle_seed');
            $table->unsignedInteger('revision')->default(0);
            $table->timestamps();

            $table->index(['user_id', 'lesson_id', 'status']);
        });

        // MySQL/MariaDB: at most one in_progress attempt per user per lesson.
        // A generated column is 1 while in progress and NULL otherwise; NULLs do
        // not collide in a unique index (MySQL has no partial indexes).
        // SQLite and other drivers skip this — application code and insert-race
        // recovery in AttemptService hold the line there.
        if (in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            Schema::table('lesson_attempts', function (Blueprint $table) {
                $table->unsignedTinyInteger('in_progress_guard')
                    ->nullable()
                    ->storedAs("CASE WHEN `status` = 'in_progress' THEN 1 ELSE NULL END");

                $table->unique(
                    ['user_id', 'lesson_id', 'in_progress_guard'],
                    'lesson_attempts_one_in_progress'
                );
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('lesson_attempts');
    }
};
