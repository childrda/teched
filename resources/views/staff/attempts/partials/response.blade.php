{{--
    One stored block payload, already resolved by AttemptStateFormatter. Used
    for the draft and for every submission in history — the shape is uniform
    per block type, so this file branches on `mode` and never on block type.
    No resolution logic belongs here.
--}}
@php
    /** @var array<string, mixed> $formatted */
@endphp

@if ($formatted['mode'] === 'empty')
    {{-- Nothing stored; the caller has already said so in words. --}}
@elseif ($formatted['mode'] === 'raw')
    <p class="mt-2 text-sm text-hazard-700">{{ __('staff.response_unrecognized') }}</p>
    <pre class="mt-2 overflow-x-auto rounded border-2 border-steel-300 bg-steel-100 p-3 text-sm text-steel-950">{{ $formatted['raw'] }}</pre>
@elseif ($formatted['mode'] === 'text')
    @foreach ($formatted['items'] as $item)
        <p class="mt-2 max-w-[68ch] whitespace-pre-wrap text-steel-950">{{ $item['value'] }}</p>
    @endforeach
@else
    @if ($formatted['has_unresolved_values'])
        <p class="mt-2 text-sm text-hazard-700">{{ __('staff.response_partly_unresolved') }}</p>
    @endif

    <dl class="mt-2 space-y-2">
        @foreach ($formatted['items'] as $item)
            <div @class(['border-l-4 pl-3', 'border-hazard' => ! $item['resolved'], 'border-steel-300' => $item['resolved']])>
                <dt class="text-sm font-semibold text-steel-700">{{ $item['label'] }}</dt>
                <dd class="max-w-[68ch] whitespace-pre-wrap text-steel-950">{{ $item['value'] }}</dd>
            </div>
        @endforeach
    </dl>
@endif
