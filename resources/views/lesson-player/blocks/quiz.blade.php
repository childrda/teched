@php
    $questions = array_values($config['questions'] ?? []);

    // Shuffle in Blade (not Alpine) so speakableText ids and data-speech-id
    // stay aligned. Algorithm: app/Support/SeededShuffle.php — mirrored in
    // resources/js/lesson-player/seeded-shuffle.js for the matching bank.
    if (($config['shuffle_questions'] ?? false) === true) {
        $questions = \App\Support\SeededShuffle::shuffle(
            $questions,
            (string) (($playerAttempt ?? [])['shuffle_seed'] ?? ''),
            (string) $blockId
        );
    }

    $activity = [
        'blockId' => $blockId,
        'pageId' => $pageId,
        'completionType' => $completionType ?? '',
        // Already shuffled above when requested; the Alpine side must not
        // shuffle again or speech ids and radios would drift apart.
        'shuffle' => false,
        'questions' => array_values(array_map(
            fn (array $question) => [
                'id' => $question['id'],
                'prompt' => $question['prompt'],
                'options' => array_values(array_map(
                    fn (array $option) => [
                        'id' => $option['id'],
                        'text' => $option['text'],
                    ],
                    $question['options'] ?? []
                )),
            ],
            $questions
        )),
        'strings' => [
            'gate' => __('quiz.gate'),
            'gate_pass' => __('quiz.gate_pass'),
            'submit' => __('quiz.submit'),
            'submitting' => __('quiz.submitting'),
            'retry' => __('quiz.retry'),
            'answer_every' => __('quiz.answer_every'),
            'result_heading' => __('quiz.result_heading'),
            'score' => __('quiz.score'),
            'passed' => __('quiz.passed'),
            'failed' => __('quiz.failed'),
            'passed_symbol' => __('quiz.passed_symbol'),
            'failed_symbol' => __('quiz.failed_symbol'),
            'error' => __('quiz.error'),
            'error_retry' => __('quiz.error_retry'),
        ],
    ];
@endphp

{{--
    Quiz. Answers and feedback are not in this page: questions arrive without
    answer_id, feedback, or source_ref. Questions are drawn server-side so
    every speakableText id has a matching data-speech-id before Alpine runs.
    Submit grades through the server; attempt state lives only in this
    component until Phase 3.
--}}
<div class="player-card space-y-6"
     x-data="quizActivity(@js($activity))"
     x-init="captureDisposer(addContributor(@js($pageId), contributor()))">

    @foreach ($questions as $questionIndex => $question)
        @php
            $questionId = $question['id'];
            $questionIdJs = \Illuminate\Support\Js::from($questionId);
        @endphp

        <fieldset class="space-y-3 rounded-md border-2 border-slate-300 p-4"
                  data-question-id="{{ $questionId }}">
            <legend class="px-1 text-base font-semibold" data-speech-id="{{ $questionId }}:prompt">
                {{ ($questionIndex + 1).'. '.$question['prompt'] }}
            </legend>

            <div class="space-y-2">
                @foreach ($question['options'] ?? [] as $option)
                    <label class="flex min-h-11 cursor-pointer items-start gap-3 rounded-md border-2 border-transparent px-2 py-1 hover:bg-slate-50">
                        <input type="radio"
                               class="mt-1 h-5 w-5 border-2 border-slate-600"
                               name="quiz-{{ $blockId }}-{{ $questionId }}"
                               value="{{ $option['id'] }}"
                               data-speech-id="{{ $questionId }}:{{ $option['id'] }}"
                               x-model="answers[{{ $questionIdJs }}]"
                               @change="onAnswer()"
                               :disabled="readOnly">
                        <span class="text-base/7">{{ $option['text'] }}</span>
                    </label>
                @endforeach
            </div>
        </fieldset>
    @endforeach

    <div class="flex flex-wrap items-center gap-3">
        <button type="button"
                class="player-btn player-btn-primary"
                x-show="! readOnly"
                :aria-disabled="submitting ? 'true' : 'false'"
                @click="submit()"
                x-text="submitting ? strings.submitting : (latestResult ? strings.retry : strings.submit)"></button>
    </div>

    {{-- Visible only. Deliberately not a live region: the sr-only region
         below announces this text via announce(), so a second status here
         would make a screen reader hear the result twice. --}}
    <template x-if="latestResult">
        <div class="rounded-md border-2 border-slate-500 bg-slate-50 p-4">
            <h3 class="font-semibold" x-text="strings.result_heading"></h3>
            <p class="mt-2 text-base" x-text="resultSummary"></p>
            <p class="mt-2 text-base font-semibold">
                <span aria-hidden="true" x-text="latestResult.passed ? strings.passed_symbol : strings.failed_symbol"></span>
                <span x-text="latestResult.passed ? strings.passed : strings.failed"></span>
            </p>
        </div>
    </template>

    <template x-if="error">
        <div class="rounded-md border-2 border-amber-800 bg-amber-50 p-4">
            <p class="font-semibold text-amber-950" x-text="error"></p>
            <button type="button"
                    class="player-btn player-btn-secondary player-btn-sm mt-3"
                    @click="submit()"
                    x-text="strings.error_retry"></button>
        </div>
    </template>

    <p class="sr-only" role="status" aria-live="polite" x-text="announcement"></p>
</div>
