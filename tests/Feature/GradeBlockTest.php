<?php

use App\Enums\LessonStatus;
use App\Models\Lesson;
use App\Models\LessonBlock;
use App\Models\LessonPage;
use App\Models\LessonVersion;
use App\Models\User;
use App\Services\AttemptService;
use App\Services\GradingToken;
use App\Services\LessonPublisher;
use App\Services\StudentManifest;
use Database\Seeders\WeldingLessonSeeder;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->withoutVite();
    asStudent();
});

function publishLesson(Lesson $lesson): Lesson
{
    app(LessonPublisher::class)->publish($lesson, User::factory()->create());

    return $lesson->fresh();
}

function studentPayload(Lesson $lesson): array
{
    return app(StudentManifest::class)->forLesson($lesson->fresh());
}

function gradeUrl(string $code, string $blockId): string
{
    return "/player/lessons/{$code}/blocks/{$blockId}/grade";
}

function quizBlockId(array $pages): string
{
    return blockOfType($pages, 'quiz')['block_id'];
}

function correctQuizAnswers(array $compiledConfig): array
{
    $answers = [];

    foreach ($compiledConfig['questions'] as $question) {
        $answers[$question['id']] = $question['answer_id'];
    }

    return $answers;
}

function grade(string $code, string $blockId, mixed $token, mixed $response)
{
    $lesson = Lesson::query()->where('code', $code)->first();

    // Grading requires an in_progress attempt; create one when the lesson is
    // playable so existing suite cases need not visit the player first.
    if ($lesson !== null && auth()->user() !== null) {
        app(AttemptService::class)->resolveForPlayer(auth()->user(), $lesson);
    }

    $payload = ['response' => $response];

    // Omit the key entirely when the token is null so "absent" is not
    // confused with an explicit null value.
    if ($token !== null) {
        $payload['version_token'] = $token;
    }

    return test()->postJson(gradeUrl($code, $blockId), $payload);
}

test('a valid complete submission returns exactly the five public keys', function () {
    $this->seed(WeldingLessonSeeder::class);

    $lesson = Lesson::query()->where('code', 'WEL-6.1.1')->firstOrFail();
    $manifest = $lesson->currentVersion()->manifest;
    $payload = studentPayload($lesson);
    $quiz = blockOfType($manifest['pages'], 'quiz');
    $answers = correctQuizAnswers($quiz['config']);

    $response = grade($lesson->code, $quiz['block_id'], $payload['grading_token'], $answers)
        ->assertOk()
        ->json();

    expect(array_keys($response))->toEqualCanonicalizing([
        'score',
        'max_score',
        'percentage',
        'passed',
        'requires_manual_review',
    ])->and(count($response))->toBe(5);

    expect($response['score'])->toBe(10)
        ->and($response['max_score'])->toBe(10)
        ->and($response['percentage'])->toBe(100)
        ->and($response['passed'])->toBeTrue()
        ->and($response['requires_manual_review'])->toBeFalse();
});

test('the public grading body never carries details, correctness, feedback, or answer keys', function () {
    $this->seed(WeldingLessonSeeder::class);

    $lesson = Lesson::query()->where('code', 'WEL-6.1.1')->firstOrFail();
    $manifest = $lesson->currentVersion()->manifest;
    $payload = studentPayload($lesson);
    $quiz = blockOfType($manifest['pages'], 'quiz');

    $wrong = correctQuizAnswers($quiz['config']);
    $firstId = array_key_first($wrong);
    $options = collect($quiz['config']['questions'])->firstWhere('id', $firstId)['options'];
    $wrong[$firstId] = collect($options)->first(
        fn (array $option) => $option['id'] !== $wrong[$firstId]
    )['id'];

    $body = grade($lesson->code, $quiz['block_id'], $payload['grading_token'], $wrong)
        ->assertOk()
        ->json();

    $encoded = json_encode($body);

    foreach (['details', 'correct', 'feedback', 'answer_id', 'source_ref'] as $forbidden) {
        expect($encoded)->not->toContain($forbidden);
    }

    foreach ($quiz['config']['questions'] as $question) {
        expect($encoded)->not->toContain($question['feedback']);
    }
});

test('quiz response validation rejects incomplete and malformed payloads', function (callable $build) {
    $this->seed(WeldingLessonSeeder::class);

    $lesson = Lesson::query()->where('code', 'WEL-6.1.1')->firstOrFail();
    $manifest = $lesson->currentVersion()->manifest;
    $payload = studentPayload($lesson);
    $quiz = blockOfType($manifest['pages'], 'quiz');
    $answers = correctQuizAnswers($quiz['config']);

    grade($lesson->code, $quiz['block_id'], $payload['grading_token'], $build($answers, $quiz['config']))
        ->assertStatus(422);
})->with([
    'missing question' => [
        function (array $answers) {
            array_shift($answers);

            return $answers;
        },
    ],
    'unknown question id' => [
        function (array $answers) {
            $answers['not-a-real-question'] = 'q1-a';

            return $answers;
        },
    ],
    'extra entry' => [
        function (array $answers) {
            $answers['extra-question'] = 'q1-a';

            return $answers;
        },
    ],
    'unknown option id' => [
        function (array $answers) {
            $first = array_key_first($answers);
            $answers[$first] = 'not-an-option';

            return $answers;
        },
    ],
    'empty response' => [
        fn () => [],
    ],
    'absent response' => [
        fn () => null,
    ],
]);

test('non-auto-gradable and placement blocks cannot be graded through the endpoint', function (string $type) {
    $lesson = publishLesson(createLessonWithAllBlockTypes());
    $payload = studentPayload($lesson);
    $block = blockOfType($payload['pages'], $type);

    // Same generic body whether the type is not auto-gradable or is
    // auto-gradable but has no submit path yet — neither reveals which.
    grade($lesson->code, $block['block_id'], $payload['grading_token'], ['anything' => 'goes'])
        ->assertStatus(422)
        ->assertExactJson(['message' => 'This block cannot be graded.']);
})->with(['short_response', 'cer', 'matching', 'image_labeling']);

test('a null grade from an auto-gradable type is a server error, not a 200', function () {
    $registry = app(App\Blocks\BlockTypeRegistry::class);
    $original = $registry->get('quiz');

    $registry->register(new class($original) extends App\Blocks\AbstractBlockType
    {
        public function __construct(private App\Blocks\Contracts\BlockType $inner)
        {
        }

        public function key(): string
        {
            return $this->inner->key();
        }

        public function label(): string
        {
            return $this->inner->label();
        }

        public function isAutoGradable(): bool
        {
            return true;
        }

        public function collectsResponse(): bool
        {
            return true;
        }

        public function gradingResponseShape(): ?string
        {
            return 'quiz_answers';
        }

        public function configRules(): array
        {
            return $this->inner->configRules();
        }

        public function defaultConfig(): array
        {
            return $this->inner->defaultConfig();
        }

        public function compileConfig(array $validatedConfig): array
        {
            return $this->inner->compileConfig($validatedConfig);
        }

        public function redactConfig(array $compiledConfig): array
        {
            return $this->inner->redactConfig($compiledConfig);
        }

        public function speakableText(array $redactedConfig): array
        {
            return $this->inner->speakableText($redactedConfig);
        }

        public function grade(array $compiledConfig, ?array $grading, array $response): ?array
        {
            return null;
        }
    });

    try {
        $lesson = publishLesson(createLessonWithAllBlockTypes());
        $payload = studentPayload($lesson);
        $quiz = blockOfType($lesson->currentVersion()->manifest['pages'], 'quiz');
        $answers = correctQuizAnswers($quiz['config']);

        grade($lesson->code, $quiz['block_id'], $payload['grading_token'], $answers)
            ->assertStatus(500);
    } finally {
        $registry->register($original);
    }
});

test('an unknown block id returns 404', function () {
    $lesson = publishLesson(createLessonWithAllBlockTypes());
    $payload = studentPayload($lesson);

    grade($lesson->code, 'NO-SUCH-BLOCK', $payload['grading_token'], ['q' => 'a'])
        ->assertNotFound();
});

test('a block id belonging to a different lesson returns 404', function () {
    $lessonA = publishLesson(createLessonWithAllBlockTypes());
    $lessonB = publishLesson(createLessonWithAllBlockTypes());

    $tokenA = studentPayload($lessonA)['grading_token'];
    $blockB = quizBlockId(studentPayload($lessonB)['pages']);
    $answers = correctQuizAnswers(
        blockOfType($lessonB->currentVersion()->manifest['pages'], 'quiz')['config']
    );

    grade($lessonA->code, $blockB, $tokenA, $answers)->assertNotFound();
});

test('every token failure returns the same generic 422 body', function (callable $token) {
    $lesson = publishLesson(createLessonWithAllBlockTypes());
    $other = publishLesson(createLessonWithAllBlockTypes());
    $payload = studentPayload($lesson);
    $quiz = blockOfType($lesson->currentVersion()->manifest['pages'], 'quiz');
    $answers = correctQuizAnswers($quiz['config']);

    $expected = ['message' => 'The grading session is invalid.'];

    $response = grade(
        $lesson->code,
        $quiz['block_id'],
        $token($payload['grading_token'], $other, $lesson),
        $answers
    )->assertStatus(422);

    expect($response->json())->toBe($expected)
        ->and($response->getContent())->toBe(json_encode($expected));
})->with([
    'garbage token' => [fn () => 'not-a-real-token'],
    'token for a different lesson' => [
        fn (string $own, $other) => studentPayload($other)['grading_token'],
    ],
    'absent token' => [fn () => null],
    'non-string token' => [fn () => ['nested' => true]],
]);

test('a structurally valid token whose version row is gone returns the generic 422', function () {
    $lesson = publishLesson(createLessonWithAllBlockTypes());
    $v1 = $lesson->currentVersion();
    $payload = studentPayload($lesson);
    $quiz = blockOfType($v1->manifest['pages'], 'quiz');
    $answers = correctQuizAnswers($quiz['config']);
    $token = $payload['grading_token'];

    // Keep the lesson servable under a newer version, then remove only the
    // row the token points at — the failure must be the generic token 422,
    // not a lesson-level 404.
    $lesson->forceFill(['has_unpublished_changes' => true])->save();
    publishLesson($lesson);
    expect($lesson->fresh()->current_version)->toBe(2);

    DB::table('lesson_versions')->where('id', $v1->id)->delete();

    $expected = ['message' => 'The grading session is invalid.'];

    $response = grade($lesson->code, $quiz['block_id'], $token, $answers)->assertStatus(422);

    expect($response->json())->toBe($expected);
});

test('unpublished and archived lessons return 404 from the grading endpoint', function () {
    $lesson = publishLesson(createLessonWithAllBlockTypes());
    $payload = studentPayload($lesson);
    $quiz = blockOfType($lesson->currentVersion()->manifest['pages'], 'quiz');
    $answers = correctQuizAnswers($quiz['config']);

    $lesson->forceFill(['status' => LessonStatus::Draft])->save();

    grade($lesson->code, $quiz['block_id'], $payload['grading_token'], $answers)->assertNotFound();

    $lesson->forceFill(['status' => LessonStatus::Published])->save();
    expect(studentPayload($lesson))->not->toBeNull();

    $lesson->forceFill(['status' => LessonStatus::Archived])->save();

    grade($lesson->code, $quiz['block_id'], $payload['grading_token'], $answers)->assertNotFound();
});

test('grading is bound to the version in the token, not the current publish', function () {
    $lesson = Lesson::factory()->create();
    $page = LessonPage::factory()->create(['lesson_id' => $lesson->id, 'position' => 1]);

    LessonBlock::factory()->create([
        'lesson_page_id' => $page->id,
        'type' => 'quiz',
        'position' => 1,
        'config' => [
            'questions' => [
                [
                    'id' => 'q1',
                    'prompt' => 'Pick one',
                    'options' => [
                        ['id' => 'a', 'text' => 'Alpha'],
                        ['id' => 'b', 'text' => 'Beta'],
                    ],
                    'answer_id' => 'a',
                    'feedback' => null,
                    'source_ref' => null,
                ],
            ],
            'shuffle_questions' => false,
        ],
        'grading' => fullGradingShape('all_correct'),
    ]);

    publishLesson($lesson);

    $v1Payload = studentPayload($lesson);
    $v1Token = $v1Payload['grading_token'];
    $blockId = quizBlockId($v1Payload['pages']);

    // v1 key says "a" is correct — creates an attempt pinned to v1.
    grade($lesson->code, $blockId, $v1Token, ['q1' => 'a'])
        ->assertOk()
        ->assertJson(['score' => 1, 'passed' => true]);

    $block = $page->blocks()->first();
    $config = $block->config;
    $config['questions'][0]['answer_id'] = 'b';
    $block->forceFill(['config' => $config])->save();
    $lesson->forceFill(['has_unpublished_changes' => true])->save();

    publishLesson($lesson);

    // Mid-attempt republish: the attempt stays on v1, so the v1 token still
    // grades with v1's key and a v2 token is rejected as a pin mismatch.
    grade($lesson->code, $blockId, $v1Token, ['q1' => 'a'])
        ->assertOk()
        ->assertJson(['score' => 1, 'passed' => true]);

    $v2Token = studentPayload($lesson)['grading_token'];

    grade($lesson->code, $blockId, $v2Token, ['q1' => 'a'])
        ->assertStatus(422)
        ->assertJsonPath('message', 'The grading session is invalid.');

    // A brand-new student is pinned to v2, where "a" is now wrong.
    asStudent();
    grade($lesson->code, $blockId, $v2Token, ['q1' => 'a'])
        ->assertOk()
        ->assertJson(['score' => 0, 'passed' => false]);
});

test('a forged encrypt payload for another lesson is rejected', function () {
    $lesson = publishLesson(createLessonWithAllBlockTypes());
    $other = publishLesson(createLessonWithAllBlockTypes());
    $quiz = blockOfType($lesson->currentVersion()->manifest['pages'], 'quiz');

    $forged = Crypt::encryptString(json_encode([
        'lesson_id' => (int) $other->id,
        'version_id' => (int) $other->currentVersion()->id,
    ]));

    grade($lesson->code, $quiz['block_id'], $forged, correctQuizAnswers($quiz['config']))
        ->assertStatus(422)
        ->assertExactJson(['message' => 'The grading session is invalid.']);
});

test('GradingToken resolve accepts only its own lesson', function () {
    $lesson = publishLesson(createLessonWithAllBlockTypes());
    $other = publishLesson(createLessonWithAllBlockTypes());
    $token = app(GradingToken::class)->issue($lesson->currentVersion());

    expect(fn () => app(GradingToken::class)->resolve($token, $other))
        ->toThrow(App\Exceptions\InvalidGradingToken::class);
});
