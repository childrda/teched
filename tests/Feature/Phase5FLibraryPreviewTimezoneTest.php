<?php

use App\Enums\LessonStatus;
use App\Enums\UserRole;
use App\Filament\Resources\Lessons\LessonResource;
use App\Filament\Resources\Lessons\Pages\EditLesson;
use App\Filament\Resources\Lessons\Pages\ListLessons;
use App\Filament\Resources\Lessons\Resources\LessonPages\Pages\EditLessonPage;
use App\Models\Lesson;
use App\Models\LessonAttempt;
use App\Models\LessonPage;
use App\Models\LessonVersion;
use App\Models\User;
use App\Services\LessonAssignmentService;
use App\Services\LessonAuthoringService;
use App\Services\LessonPublisher;
use App\Services\SchoolClassService;
use App\Support\DisplayTime;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Livewire\Livewire;

beforeEach(function () {
    $this->withoutVite();
});

function phase5fOwnedLesson(User $owner, string $html = '<p>Phase 5F body</p>'): Lesson
{
    $service = app(LessonAuthoringService::class);

    return $service->create([
        'code' => 'P5F-'.Str::upper(Str::random(6)),
        'title' => 'Phase 5F lesson',
        'settings' => Lesson::DEFAULT_SETTINGS,
        'learning_target' => 'Learn',
        'success_criteria' => ['one'],
        'pages' => [[
            'page_id' => (string) Str::ulid(),
            'title' => 'Page one',
            'completion_type' => 'view',
            'settings' => LessonPage::DEFAULT_SETTINGS,
            'blocks' => [[
                'type' => 'rich_text',
                'data' => [
                    'block_id' => (string) Str::ulid(),
                    'html' => $html,
                    'grading' => null,
                ],
            ]],
        ]],
    ], $owner);
}

test('owner gets both previews; non-owner only published; draft preview stays owner-only', function () {
    $owner = asTeacher();
    $viewer = User::factory()->create();
    $viewer->forceFill(['role' => UserRole::Teacher])->save();

    $lesson = phase5fOwnedLesson($owner, '<p>v1-marker-UNIQUE</p>');
    app(LessonPublisher::class)->publish($lesson->fresh(), $owner);

    expect(Gate::forUser($owner)->allows('previewDraft', $lesson->fresh()))->toBeTrue()
        ->and(Gate::forUser($owner)->allows('previewPublished', $lesson->fresh()))->toBeTrue()
        ->and(Gate::forUser($viewer)->allows('previewDraft', $lesson->fresh()))->toBeFalse()
        ->and(Gate::forUser($viewer)->allows('previewPublished', $lesson->fresh()))->toBeTrue();

    $this->actingAs($viewer)
        ->get(route('authoring.lessons.preview', $lesson))
        ->assertForbidden();

    $this->actingAs($viewer)
        ->get(route('authoring.lessons.preview-published', $lesson))
        ->assertOk()
        ->assertSee('Previewing published version', false)
        ->assertSee('v1-marker-UNIQUE', false);
});

test('published preview never leaks unpublished draft changes and creates no attempt', function () {
    $owner = asTeacher();
    $viewer = User::factory()->create();
    $viewer->forceFill(['role' => UserRole::Teacher])->save();

    $lesson = phase5fOwnedLesson($owner, '<p>published-v1-CONTENT</p>');
    app(LessonPublisher::class)->publish($lesson->fresh(), $owner);

    $page = $lesson->fresh(['pages.blocks'])->pages->first();
    $form = app(LessonAuthoringService::class)->toPageFormState($page);
    $form['blocks'][0]['data']['html'] = '<p>DRAFT-SECRET-SHOULD-NOT-LEAK</p>';
    app(LessonAuthoringService::class)->savePage($page, $form, $owner);

    $versionsBefore = LessonVersion::query()->where('lesson_id', $lesson->id)->count();
    $attemptsBefore = LessonAttempt::query()->where('lesson_id', $lesson->id)->count();

    $publishedHtml = $this->actingAs($viewer)
        ->get(route('authoring.lessons.preview-published', $lesson))
        ->assertOk()
        ->assertSee('published-v1-CONTENT', false)
        ->assertDontSee('DRAFT-SECRET-SHOULD-NOT-LEAK', false)
        ->assertSee('version 1', false)
        ->getContent();

    expect($publishedHtml)->not->toContain('grading_token')
        ->and($publishedHtml)->toContain('canPersist\u0022:false')
        ->and($publishedHtml)->toContain('canGrade\u0022:false')
        ->and(LessonVersion::query()->where('lesson_id', $lesson->id)->count())->toBe($versionsBefore)
        ->and(LessonAttempt::query()->where('lesson_id', $lesson->id)->count())->toBe($attemptsBefore);

    $this->actingAs($owner)
        ->get(route('authoring.lessons.preview', $lesson))
        ->assertOk()
        ->assertSee('DRAFT-SECRET-SHOULD-NOT-LEAK', false)
        ->assertDontSee('published-v1-CONTENT', false);
});

test('library SQL scope includes own any-status and others published only', function () {
    $teacher = asTeacher();
    $other = User::factory()->create();
    $other->forceFill(['role' => UserRole::Teacher])->save();

    $mineDraft = phase5fOwnedLesson($teacher);
    $mineArchived = phase5fOwnedLesson($teacher);
    $mineArchived->forceFill(['status' => LessonStatus::Archived])->save();

    $otherDraft = phase5fOwnedLesson($other);
    $otherPublished = phase5fOwnedLesson($other, '<p>library</p>');
    app(LessonPublisher::class)->publish($otherPublished->fresh(), $other);
    $otherArchived = phase5fOwnedLesson($other);
    app(LessonPublisher::class)->publish($otherArchived->fresh(), $other);
    app(LessonAuthoringService::class)->archive($otherArchived->fresh(), $other);

    Auth::login($teacher);
    $ids = LessonResource::getEloquentQuery()->pluck('id')->all();

    expect($ids)->toContain($mineDraft->id)
        ->and($ids)->toContain($mineArchived->id)
        ->and($ids)->toContain($otherPublished->id)
        ->and($ids)->not->toContain($otherDraft->id)
        ->and($ids)->not->toContain($otherArchived->id);

    // Filtering is SQL-side — not a collection filter after load.
    $sql = LessonResource::getEloquentQuery()->toSql();
    expect(strtolower($sql))->toContain('created_by_user_id')
        ->and(strtolower($sql))->toContain('current_version');
});

test('mutations on another teachers published lesson are denied by direct invocation', function () {
    $owner = asTeacher();
    $viewer = User::factory()->create();
    $viewer->forceFill(['role' => UserRole::Teacher])->save();

    $lesson = phase5fOwnedLesson($owner);
    app(LessonPublisher::class)->publish($lesson->fresh(), $owner);
    $lesson = $lesson->fresh();
    $page = $lesson->pages()->first();

    foreach (['update', 'publish', 'archive', 'unarchive', 'duplicate', 'delete', 'previewDraft', 'reassignOwner'] as $ability) {
        expect(Gate::forUser($viewer)->denies($ability, $lesson))->toBeTrue($ability);
    }

    $this->actingAs($viewer)
        ->get(LessonResource::getUrl('edit', ['record' => $lesson]))
        ->assertForbidden();

    Livewire::actingAs($viewer)
        ->test(EditLesson::class, ['record' => $lesson->getKey()])
        ->assertForbidden();

    Livewire::actingAs($viewer)
        ->test(EditLessonPage::class, [
            'record' => $page->getKey(),
            'parentRecord' => $lesson,
        ])
        ->assertForbidden();
});

test('list marks district library lessons as read-only for non-owners', function () {
    $owner = asTeacher();
    $viewer = User::factory()->create();
    $viewer->forceFill(['role' => UserRole::Teacher])->save();

    $lesson = phase5fOwnedLesson($owner);
    app(LessonPublisher::class)->publish($lesson->fresh(), $owner);

    Livewire::actingAs($viewer)
        ->test(ListLessons::class)
        ->assertSuccessful()
        ->assertSee('District library')
        ->assertSee('Read-only')
        ->assertSee('Preview published')
        ->assertDontSee('Preview saved draft');
});

test('eastern 3pm stores as utc and displays as 3pm in january and july', function () {
    config(['app.timezone' => 'UTC', 'app.display_timezone' => 'America/New_York']);

    $jan = DisplayTime::parseInput('2026-01-15 15:00:00');
    $jul = DisplayTime::parseInput('2026-07-30 15:00:00');

    expect($jan->timezone('UTC')->format('Y-m-d H:i:s'))->toBe('2026-01-15 20:00:00')
        ->and($jul->timezone('UTC')->format('Y-m-d H:i:s'))->toBe('2026-07-30 19:00:00')
        ->and(DisplayTime::toDayDateTimeString($jan))->toContain('3:00 PM')
        ->and(DisplayTime::toDayDateTimeString($jul))->toContain('3:00 PM');

    $teacher = asTeacher();
    $class = app(SchoolClassService::class)->create($teacher, [
        'name' => 'Period 5F TZ',
        'school_year' => '2026-2027',
    ]);
    $lesson = phase5fOwnedLesson($teacher);
    app(LessonPublisher::class)->publish($lesson->fresh(), $teacher);

    $assignment = app(LessonAssignmentService::class)->create($class, $teacher, [
        'lesson_id' => $lesson->id,
        'available_at' => '2026-07-30 15:00:00',
        'due_at' => '2026-01-15 15:00:00',
    ]);

    $raw = DB::table('lesson_assignments')->where('id', $assignment->id)->first();
    expect((string) $raw->available_at)->toStartWith('2026-07-30 19:00:00')
        ->and((string) $raw->due_at)->toStartWith('2026-01-15 20:00:00');

    $fresh = $assignment->fresh();
    expect(DisplayTime::toDayDateTimeString($fresh->available_at))->toContain('3:00 PM')
        ->and(DisplayTime::toDayDateTimeString($fresh->due_at))->toContain('3:00 PM');
});

test('sessions table exists and session cookie is teched_session', function () {
    expect(Schema::hasTable('sessions'))->toBeTrue()
        ->and(config('session.cookie'))->toBe('teched_session')
        ->and(config('session.driver'))->toBe('array'); // phpunit; .env.example uses database

    $example = file_get_contents(base_path('.env.example'));
    expect($example)->toContain('SESSION_DRIVER=database')
        ->and($example)->toContain('SESSION_COOKIE=teched_session')
        ->and($example)->toContain('APP_TIMEZONE=UTC')
        ->and($example)->toContain('APP_DISPLAY_TIMEZONE=America/New_York');
});

test('cross-links are staff-only on home and staff layout', function () {
    $teacher = asTeacher();
    $this->actingAs($teacher)
        ->get(route('home'))
        ->assertOk()
        ->assertSee(__('staff.authoring_panel'), false)
        ->assertSee('/admin', false);

    $this->actingAs($teacher)
        ->get(route('staff.classes.index'))
        ->assertOk()
        ->assertSee(__('staff.authoring_panel'), false)
        ->assertSee('/admin', false);

    $student = asStudent();
    $this->actingAs($student)
        ->get(route('home'))
        ->assertOk()
        ->assertDontSee(__('staff.authoring_panel'), false);
});
