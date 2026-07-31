@php
    $textareaId = 'short-response-'.$blockId;
    $hintId = 'short-response-hint-'.$blockId;
    $activity = [
        'blockId' => $blockId,
        'pageId' => $pageId,
        'minLength' => $config['min_length'] ?? null,
        'strings' => [
            'gate' => __('response.gate_short'),
            'characters_remaining' => __('response.characters_remaining'),
            'characters_over' => __('response.characters_over'),
            'characters_met' => __('response.characters_met'),
            'characters_needed' => __('response.characters_needed'),
            'awaiting_review' => __('response.awaiting_review'),
            'reviewed_heading' => __('response.reviewed_heading'),
            'reviewed_score' => __('response.reviewed_score'),
            'reviewed_no_score' => __('response.reviewed_no_score'),
        ],
    ];
@endphp

{{--
    Short response. The rubric is stripped at redaction and must never appear
    here. Length feedback is plain text that re-renders — not a live region —
    so every keystroke is not announced.
--}}
<div class="player-card space-y-4"
     x-data="shortResponseActivity(@js($activity))"
     x-init="captureDisposer(addContributor(@js($pageId), contributor()))">

    <div class="player-prose" data-speech-id="prompt">
        {!! $config['prompt_html'] ?? '' !!}
    </div>

    <div>
        <label class="player-field-label" for="{{ $textareaId }}">{{ __('response.label_short') }}</label>

        <textarea id="{{ $textareaId }}"
                  class="mt-2 min-h-32 w-full rounded-md border-2 border-slate-500 bg-white px-3 py-2 text-base"
                  x-model="value"
                  @input="onInput()"
                  :readonly="readOnly"
                  @if (filled($config['placeholder'] ?? null)) placeholder="{{ $config['placeholder'] }}" @endif
                  aria-describedby="{{ $hintId }}"></textarea>

        <p id="{{ $hintId }}" class="mt-2 min-h-6 text-sm text-slate-700" x-text="hint"></p>
    </div>

    <div class="rounded border-2 border-slate-400 bg-slate-50 p-3 text-sm" x-show="review" x-cloak>
        <template x-if="review && ! review.reviewed">
            <p x-text="strings.awaiting_review"></p>
        </template>
        <template x-if="review && review.reviewed">
            <div>
                <p class="font-semibold" x-text="strings.reviewed_heading"></p>
                <p class="mt-1"
                   x-text="review.score
                        ? strings.reviewed_score
                            .replace(':awarded', review.score.awarded)
                            .replace(':possible', review.score.possible)
                            .replace(':percent', review.score.percentage)
                        : strings.reviewed_no_score"></p>
                <p class="mt-2 whitespace-pre-wrap" x-show="review.comment" x-text="review.comment"></p>
            </div>
        </template>
    </div>
</div>
