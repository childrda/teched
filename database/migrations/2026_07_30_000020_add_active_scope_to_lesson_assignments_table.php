<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $driver = Schema::getConnection()->getDriverName();
        $isMysql = in_array($driver, ['mysql', 'mariadb'], true);

        // MySQL/MariaDB only — SQLite relies on LessonAssignmentService
        // application-level checks for one-active uniqueness (same pattern as
        // in_progress_scope). GROUP_CONCAT is MySQL syntax and only matters
        // when we are about to add the unique index below.
        if ($isMysql) {
            // Before constraining active uniqueness, refuse if pre-existing
            // duplicates would make the index fail with an opaque error. Do
            // not auto-archive — a migration cannot know which row to keep.
            // Runs before archived_at exists, so every row is "active."
            $duplicates = DB::table('lesson_assignments')
                ->select(
                    'school_class_id',
                    'lesson_id',
                    DB::raw('GROUP_CONCAT(id ORDER BY id) as ids'),
                    DB::raw('COUNT(*) as c')
                )
                ->groupBy('school_class_id', 'lesson_id')
                ->having('c', '>', 1)
                ->get();

            if ($duplicates->isNotEmpty()) {
                $details = $duplicates
                    ->map(fn ($row) => "class {$row->school_class_id} / lesson {$row->lesson_id}: assignment ids [{$row->ids}]")
                    ->implode('; ');

                throw new \RuntimeException(
                    'Cannot add lesson_assignments_one_active: duplicate active assignments exist. '
                    .'Archive or delete extras before migrating. Conflicts: '.$details
                );
            }
        }

        Schema::table('lesson_assignments', function (Blueprint $table) {
            $table->timestamp('archived_at')->nullable()->after('settings');
        });

        if ($isMysql) {
            Schema::table('lesson_assignments', function (Blueprint $table) {
                // Scope keys are '{classId}:{lessonId}' over bigint FKs — well
                // under 64 chars (manifest ULIDs are not used here).
                // NULLs do not collide in a unique index, so archived rows
                // (active_scope NULL) fall out of the one-active constraint.
                $table->string('active_scope', 64)
                    ->nullable()
                    ->virtualAs(
                        "CASE WHEN `archived_at` IS NULL ".
                        "THEN CONCAT(`school_class_id`, ':', `lesson_id`) ".
                        'ELSE NULL END'
                    );

                $table->unique(['active_scope'], 'lesson_assignments_one_active');
            });
        }
    }

    public function down(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if (in_array($driver, ['mysql', 'mariadb'], true) && Schema::hasColumn('lesson_assignments', 'active_scope')) {
            Schema::table('lesson_assignments', function (Blueprint $table) {
                $table->dropUnique('lesson_assignments_one_active');
                $table->dropColumn('active_scope');
            });
        }

        if (Schema::hasColumn('lesson_assignments', 'archived_at')) {
            Schema::table('lesson_assignments', function (Blueprint $table) {
                $table->dropColumn('archived_at');
            });
        }
    }
};
