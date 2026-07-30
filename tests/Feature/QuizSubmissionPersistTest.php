<?php

use App\Models\BlockSubmission;
use App\Models\Lesson;
use App\Models\User;
use App\Services\AttemptService;
use App\Services\LessonPublisher;
use App\Services\StudentManifest;
use Database\Seeders\WeldingLessonSeeder;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->withoutVite();
    asStudent();
});

test('quiz grading persists details while the HTTP envelope stays result plus attempts', function () {
    $this->seed(WeldingLessonSeeder::class);
    $lesson = Lesson::query()->where('code', 'WEL-6.1.1')->firstOrFail();
    $attempt = app(AttemptService::class)->resolveForPlayer(auth()->user(), $lesson)['attempt'];
    $manifest = $attempt->lessonVersion->manifest;
    $quiz = blockOfType($manifest['pages'], 'quiz');
    $token = app(StudentManifest::class)->forVersion($attempt->lessonVersion)['grading_token'];

    $answers = [];
    foreach ($quiz['config']['questions'] as $question) {
        $answers[$question['id']] = $question['answer_id'];
    }

    $body = $this->postJson("/player/lessons/{$lesson->code}/blocks/{$quiz['block_id']}/grade", [
        'version_token' => $token,
        'response' => $answers,
    ])->assertOk()->json();

    expect(array_keys($body))->toEqualCanonicalizing(['result', 'attempts'])
        ->and(array_key_exists('details', $body))->toBeFalse()
        ->and(array_key_exists('details', $body['result']))->toBeFalse();

    $row = BlockSubmission::query()->where('block_id', $quiz['block_id'])->firstOrFail();
    expect($row->grading_result)->toHaveKey('details')
        ->and($row->grading_result['details'])->not->toBeEmpty()
        ->and($row->attempt_number)->toBe(1)
        ->and($row->reveal_trigger)->toBe('passed');
});

test('two concurrent quiz submissions receive distinct attempt numbers', function () {
    $this->seed(WeldingLessonSeeder::class);
    $lesson = Lesson::query()->where('code', 'WEL-6.1.1')->firstOrFail();
    $attempt = app(AttemptService::class)->resolveForPlayer(auth()->user(), $lesson)['attempt'];
    $quiz = blockOfType($attempt->lessonVersion->manifest['pages'], 'quiz');
    $token = app(StudentManifest::class)->forVersion($attempt->lessonVersion)['grading_token'];

    $answers = [];
    foreach ($quiz['config']['questions'] as $question) {
        $answers[$question['id']] = $question['answer_id'];
    }

    // Serialize two grading writes under the same lock pattern the controller uses.
    DB::transaction(function () use ($lesson, $quiz, $token, $answers) {
        $this->postJson("/player/lessons/{$lesson->code}/blocks/{$quiz['block_id']}/grade", [
            'version_token' => $token,
            'response' => $answers,
        ])->assertOk();

        $this->postJson("/player/lessons/{$lesson->code}/blocks/{$quiz['block_id']}/grade", [
            'version_token' => $token,
            'response' => $answers,
        ])->assertOk();
    });

    $numbers = BlockSubmission::query()
        ->where('block_id', $quiz['block_id'])
        ->orderBy('attempt_number')
        ->pluck('attempt_number')
        ->all();

    expect($numbers)->toBe([1, 2]);
});
