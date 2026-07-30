<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lesson_attempts', function (Blueprint $table) {
            $table->foreignId('lesson_assignment_id')
                ->nullable()
                ->after('lesson_version_id')
                ->constrained('lesson_assignments')
                ->nullOnDelete();
        });

        // Re-key the one-active-attempt guard: one in_progress per assignment,
        // and one unassigned in_progress per lesson. Do not unique on
        // (user_id, lesson_assignment_id) alone — restart must accumulate
        // completed/superseded history under the same assignment.
        //
        // CONCAT returns NULL when any argument is NULL, so an unassigned
        // attempt (lesson_assignment_id IS NULL) falls through COALESCE to
        // the lesson-scoped key. MySQL/MariaDB only — SQLite relies on
        // AttemptService insert-race recovery, same as the prior guard.
        $driver = Schema::getConnection()->getDriverName();

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            Schema::table('lesson_attempts', function (Blueprint $table) {
                $table->dropUnique('lesson_attempts_one_in_progress');
                $table->dropColumn('in_progress_guard');
            });

            Schema::table('lesson_attempts', function (Blueprint $table) {
                // Scope keys are 'a{id}' or 'l{id}' over bigint FKs — well
                // under 64 chars (manifest ULIDs are not used here).
                $table->string('in_progress_scope', 64)
                    ->nullable()
                    ->storedAs(
                        "CASE WHEN `status` = 'in_progress' ".
                        "THEN COALESCE(CONCAT('a', `lesson_assignment_id`), CONCAT('l', `lesson_id`)) ".
                        'ELSE NULL END'
                    );

                $table->unique(
                    ['user_id', 'in_progress_scope'],
                    'lesson_attempts_one_in_progress'
                );
            });
        }
    }

    public function down(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            Schema::table('lesson_attempts', function (Blueprint $table) {
                $table->dropUnique('lesson_attempts_one_in_progress');
                $table->dropColumn('in_progress_scope');
            });

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

        Schema::table('lesson_attempts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('lesson_assignment_id');
        });
    }
};
