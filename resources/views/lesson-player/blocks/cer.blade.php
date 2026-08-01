@php
    $fields = $config['fields'] ?? [];
    $activity = [
        'blockId' => $blockId,
        'pageId' => $pageId,
        'fields' => array_map(
            fn (array $field) => [
                'id' => $field['id'],
                'minLength' => $field['min_length'] ?? null,
            ],
            $fields
        ),
        'strings' => [
            'gate' => __('response.gate_cer'),
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
    Claim–Evidence–Reasoning. One labelled textarea per field; each field has
    its own length hint and aria-describedby, matching short_response.
--}}
<div class="player-card space-y-5"
     x-data="cerActivity(@js($activity))"
     x-init="captureDisposer(addContributor(@js($pageId), contributor()))">

    <div class="player-prose" data-speech-id="scenario">
        {!! $config['scenario_html'] ?? '' !!}
    </div>

    @foreach ($fields as $field)
        @php
            $fieldId = $field['id'] ?? '';
            $textareaId = 'cer-'.$blockId.'-'.$fieldId;
            $hintId = 'cer-hint-'.$blockId.'-'.$fieldId;
            $fieldIdJs = \Illuminate\Support\Js::from($fieldId);
        @endphp

        <div>
            <label class="player-field-label" for="{{ $textareaId }}" data-speech-id="{{ $fieldId }}:label">
                {{ $field['label'] ?? '' }}
            </label>

            <textarea id="{{ $textareaId }}"
                      class="mt-2 min-h-28 w-full rounded-md border-2 border-slate-500 bg-white px-3 py-2 text-base"
                      x-model="values[{{ $fieldIdJs }}]"
                      @input="onInput()"
                      :readonly="readOnly"
                      @if (filled($field['placeholder'] ?? null)) placeholder="{{ $field['placeholder'] }}" @endif
                      aria-describedby="{{ $hintId }}"></textarea>

            <p id="{{ $hintId }}"
               class="player-meta mt-2 min-h-6 text-sm text-steel-700"
               x-text="hintFor({{ $fieldIdJs }})"></p>
        </div>
    @endforeach

    <div class="rounded border-2 border-steel-400 bg-steel-100 p-3 text-sm" x-show="review" x-cloak>
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
