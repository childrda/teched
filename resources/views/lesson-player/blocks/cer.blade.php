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
               class="mt-2 min-h-6 text-sm text-slate-700"
               x-text="hintFor({{ $fieldIdJs }})"></p>
        </div>
    @endforeach
</div>
