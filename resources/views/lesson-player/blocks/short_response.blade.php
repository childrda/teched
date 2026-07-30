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
</div>
