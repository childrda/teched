<?php

namespace Database\Seeders;

use App\Enums\ClassRole;
use App\Models\ClassMembership;
use App\Models\Lesson;
use App\Models\LessonAssignment;
use App\Models\SchoolClass;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * One welding class and one WEL 6.1.1 assignment so the roster graph is
 * exercisable after migrate:fresh --seed without manual setup.
 */
class ClassRosterSeeder extends Seeder
{
    public const CLASS_NAME = 'Welding Period 1';

    public const SCHOOL_YEAR = '2026-2027';

    public function run(): void
    {
        $teacher = User::query()->where('email', UserSeeder::TEACHER_EMAIL)->firstOrFail();
        $student1 = User::query()->where('email', 'student1@teched.test')->firstOrFail();
        $student2 = User::query()->where('email', 'student2@teched.test')->firstOrFail();
        $lesson = Lesson::query()->where('code', 'WEL-6.1.1')->firstOrFail();
        $version = $lesson->currentVersion();

        if ($version === null) {
            throw new \RuntimeException('WEL-6.1.1 has no published version to assign.');
        }

        $class = SchoolClass::query()->firstOrCreate(
            [
                'name' => self::CLASS_NAME,
                'school_year' => self::SCHOOL_YEAR,
                'teacher_id' => $teacher->id,
            ],
            [
                'active' => true,
                'external_provider' => null,
                'external_id' => null,
            ]
        );

        $this->ensureMembership($class, $teacher, ClassRole::Teacher);
        $this->ensureMembership($class, $student1, ClassRole::Student);
        $this->ensureMembership($class, $student2, ClassRole::Student);

        LessonAssignment::query()->firstOrCreate(
            [
                'school_class_id' => $class->id,
                'lesson_id' => $lesson->id,
                'lesson_version_id' => $version->id,
            ],
            [
                'assigned_by_user_id' => $teacher->id,
                'available_at' => now()->subDay(),
                'due_at' => now()->addWeek(),
                'settings' => null,
            ]
        );
    }

    private function ensureMembership(SchoolClass $class, User $user, ClassRole $role): void
    {
        $membership = ClassMembership::query()->firstOrNew([
            'school_class_id' => $class->id,
            'user_id' => $user->id,
        ]);

        $membership->role = $role;
        $membership->joined_at ??= now();
        $membership->withdrawn_at = null;
        $membership->save();
    }
}
