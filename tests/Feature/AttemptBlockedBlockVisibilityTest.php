<?php

use App\Blocks\BlockTypeRegistry;
use App\Enums\ClassRole;
use App\Enums\PageCompletionType;
use App\Models\ClassMembership;
use App\Models\Lesson;
use App\Models\LessonAssignment;
use App\Models\LessonBlock;
use App\Models\LessonPage;
use App\Models\SchoolClass;
use App\Models\User;
use App\Services\AttemptDetailPresenter;
use App\Services\AttemptService;
use App\Services\BlockedAttemptService;
use App\Services\LessonPublisher;
use App\Services\StudentManifest;

beforeEach(function () {
    $this->withoutVite();
});

/**
 * One pass_activity page carrying every auto-gradable block type — matching,
 * image labeling, and one or two quizzes — so "which block is blocking?" is a
 * real question rather than a foregone conclusion.
 *
 * @return array{
 *     teacher: User,
 *     student: User,
 *     lesson: Lesson,
 *     attempt: App\Models\LessonAttempt,
 *     token: string,
 *     quizzes: list<array<string, mixed>>,
 *     matching: array<string, mixed>,
 *     imageLabeling: array<string, mixed>
 * }
 */
function blockedVisibilityFixture(int $quizCount = 1): array
{
    $registry = app(BlockTypeRegistry::class);

    $teacher = asTeacher();
    $student = User::factory()->create();
    $student->refresh();

    $lesson = Lesson::factory()->create();
    $page = LessonPage::factory()->create([
        'lesson_id' => $lesson->id,
        'position' => 1,
        'completion_type' => PageCompletionType::PassActivity,
        'title' => 'Activity page',
    ]);

    $position = 1;

    foreach (['matching', 'image_labeling'] as $type) {
        LessonBlock::factory()->create([
            'lesson_page_id' => $page->id,
            'type' => $type,
            'position' => $position++,
            'config' => $registry->get($type)->defaultConfig(),
            'grading' => array_merge(fullGradingShape('all_correct'), ['max_attempts' => 1]),
        ]);
    }

    for ($i = 1; $i <= $quizCount; $i++) {
        LessonBlock::factory()->create([
            'lesson_page_id' => $page->id,
            'type' => 'quiz',
            'position' => $position++,
            'config' => [
                'shuffle_questions' => false,
                'questions' => [[
                    'id' => "q{$i}",
                    'prompt' => "Question {$i}",
                    'options' => [
                        ['id' => 'a', 'text' => 'Alpha'],
                        ['id' => 'b', 'text' => 'Beta'],
                    ],
                    'answer_id' => 'a',
                    'feedback' => 'Alpha is right.',
                    'source_ref' => null,
                ]],
            ],
            'grading' => array_merge(fullGradingShape('all_correct'), [
                'max_attempts' => 1,
                'reveal_policy' => 'never',
                'reveal_answers' => false,
            ]),
        ]);
    }

    app(LessonPublisher::class)->publish($lesson, $teacher);
    $lesson = $lesson->fresh();
    $version = $lesson->currentVersion();

    $class = SchoolClass::query()->create([
        'name' => 'Blocked Visibility Class',
        'teacher_id' => $teacher->id,
        'school_year' => '2026-2027',
        'active' => true,
    ]);

    foreach ([[$teacher, ClassRole::Teacher], [$student, ClassRole::Student]] as [$user, $role]) {
        ClassMembership::query()->create([
            'school_class_id' => $class->id,
            'user_id' => $user->id,
            'role' => $role,
            'joined_at' => now(),
        ]);
    }

    $assignment = LessonAssignment::query()->create([
        'school_class_id' => $class->id,
        'lesson_id' => $lesson->id,
        'lesson_version_id' => $version->id,
        'assigned_by_user_id' => $teacher->id,
        'available_at' => now()->subDay(),
        'due_at' => now()->addWeek(),
    ]);

    asStudent($student);
    $attempt = app(AttemptService::class)->resolveForAssignment($student, $assignment)['attempt'];
    $pages = $attempt->lessonVersion->manifest['pages'];
    $token = app(StudentManifest::class)->forVersion($attempt->lessonVersion)['grading_token'];

    $quizzes = [];

    foreach ($pages as $manifestPage) {
        foreach ($manifestPage['blocks'] ?? [] as $block) {
            if (($block['type'] ?? null) === 'quiz') {
                $quizzes[] = $block;
            }
        }
    }

    return [
        'teacher' => $teacher,
        'student' => $student,
        'lesson' => $lesson,
        'attempt' => $attempt,
        'token' => $token,
        'quizzes' => $quizzes,
        'matching' => blockOfType($pages, 'matching'),
        'imageLabeling' => blockOfType($pages, 'image_labeling'),
    ];
}

/** Burns the quiz's single allowed attempt on a wrong answer. */
function failQuiz(array $fx, array $quiz): void
{
    $questionId = $quiz['config']['questions'][0]['id'];

    test()->postJson(
        "/player/lessons/{$fx['lesson']->code}/blocks/{$quiz['block_id']}/grade",
        ['version_token' => $fx['token'], 'response' => [$questionId => 'b']]
    )->assertOk();
}

/**
 * Splits the rendered detail page into the per-block sections it draws, keyed
 * by block id, so a badge can be asserted against one section and denied for
 * the others.
 *
 * @return array<string, string>
 */
function blockSectionsByBlockId(string $html): array
{
    $sections = [];

    foreach (preg_split('/<section\b/', $html) as $chunk) {
        if (preg_match('/aria-labelledby="block-([^"]+)"/', $chunk, $matches) === 1) {
            $sections[$matches[1]] = $chunk;
        }
    }

    return $sections;
}

test('the presenter exposes the blocked block ids the shared service computed', function () {
    $fx = blockedVisibilityFixture();
    $quiz = $fx['quizzes'][0];

    asStudent($fx['student']);
    failQuiz($fx, $quiz);

    $attempt = $fx['attempt']->fresh();
    $expected = collect(app(BlockedAttemptService::class)->blockedBlocks($attempt))
        ->pluck('block_id')
        ->all();

    asTeacher($fx['teacher']);
    $detail = app(AttemptDetailPresenter::class)->present($attempt);

    // Three gradable blocks on the page, exactly one of them blocking.
    expect(collect($detail['blocks'])->where('auto_gradable', true))->toHaveCount(3)
        ->and($detail['blocked'])->toBeTrue()
        ->and($detail['blocked_block_ids'])->toBe($expected)
        ->and($detail['blocked_block_ids'])->toBe([$quiz['block_id']])
        ->and($detail['blocked_block_ids'])->not->toContain($fx['matching']['block_id'])
        ->and($detail['blocked_block_ids'])->not->toContain($fx['imageLabeling']['block_id']);
});

test('an unblocked attempt exposes no blocked block ids', function () {
    $fx = blockedVisibilityFixture();

    asTeacher($fx['teacher']);
    $detail = app(AttemptDetailPresenter::class)->present($fx['attempt']->fresh());

    expect($detail['blocked'])->toBeFalse()
        ->and($detail['blocked_block_ids'])->toBe([]);
});

test('the detail page badges only the blocking block and names it up top', function () {
    $fx = blockedVisibilityFixture();
    $quiz = $fx['quizzes'][0];

    asStudent($fx['student']);
    failQuiz($fx, $quiz);

    asTeacher($fx['teacher']);
    $html = $this->get(route('staff.attempts.show', $fx['attempt']))
        ->assertOk()
        ->assertSeeText(__('staff.blocked_on', ['blocks' => 'quiz']))
        ->getContent();

    $sections = blockSectionsByBlockId($html);

    expect(array_keys($sections))->toContain(
        $quiz['block_id'],
        $fx['matching']['block_id'],
        $fx['imageLabeling']['block_id'],
    );

    expect($sections[$quiz['block_id']])->toContain(__('staff.blocked_badge'))
        ->and($sections[$fx['matching']['block_id']])->not->toContain(__('staff.blocked_badge'))
        ->and($sections[$fx['imageLabeling']['block_id']])->not->toContain(__('staff.blocked_badge'));

    // The badge says "Blocked" in words, not by color alone.
    expect(__('staff.blocked_badge'))->toContain('Blocked');

    // The other two keep their working grant forms.
    foreach ([$fx['matching'], $fx['imageLabeling']] as $block) {
        expect($sections[$block['block_id']])
            ->toContain('name="block_id" value="'.$block['block_id'].'"');
    }
});

test('every simultaneously blocked block is marked', function () {
    $fx = blockedVisibilityFixture(quizCount: 2);

    asStudent($fx['student']);
    failQuiz($fx, $fx['quizzes'][0]);
    failQuiz($fx, $fx['quizzes'][1]);

    asTeacher($fx['teacher']);
    $attempt = $fx['attempt']->fresh();
    $detail = app(AttemptDetailPresenter::class)->present($attempt);

    expect($detail['blocked_block_ids'])->toHaveCount(2)
        ->toContain($fx['quizzes'][0]['block_id'])
        ->toContain($fx['quizzes'][1]['block_id']);

    $sections = blockSectionsByBlockId(
        $this->get(route('staff.attempts.show', $attempt))->assertOk()->getContent()
    );

    expect($sections[$fx['quizzes'][0]['block_id']])->toContain(__('staff.blocked_badge'))
        ->and($sections[$fx['quizzes'][1]['block_id']])->toContain(__('staff.blocked_badge'))
        ->and($sections[$fx['matching']['block_id']])->not->toContain(__('staff.blocked_badge'));
});

test('the grant confirmation names the block that was granted', function () {
    $fx = blockedVisibilityFixture();
    $quiz = $fx['quizzes'][0];

    asStudent($fx['student']);
    failQuiz($fx, $quiz);

    asTeacher($fx['teacher']);

    $this->post(route('staff.attempts.grant-retries', $fx['attempt']), [
        'block_id' => $quiz['block_id'],
        'additional_attempts' => 1,
    ])
        ->assertRedirect(route('staff.attempts.show', $fx['attempt']))
        ->assertSessionHas('status', __('staff.grant_recorded', ['block' => 'quiz']));

    // Granting the wrong block reads differently, which is the whole point.
    $this->post(route('staff.attempts.grant-retries', $fx['attempt']), [
        'block_id' => $fx['matching']['block_id'],
        'additional_attempts' => 1,
    ])->assertSessionHas('status', __('staff.grant_recorded', ['block' => 'matching']));

    // The grant landed on the quiz, so it is no longer blocking.
    expect(app(AttemptDetailPresenter::class)->present($fx['attempt']->fresh())['blocked_block_ids'])
        ->toBe([]);
});
