@php
    // Each style carries a text label and its own icon shape, so the meaning
    // never rests on colour alone.
    //
    // Steel / hazard / molten rather than arc for all three: arc is the
    // player's primary-action colour, and a callout that shares it competes
    // with the Continue button. Hazard is reserved for the genuine warning.
    $styles = [
        'info' => [
            'label' => 'Information',
            'box' => 'border-steel-700 bg-steel-100',
            'icon' => 'M11.25 11.25l.041-.02a.75.75 0 011.063.852l-.708 2.836a.75.75 0 001.063.852l.041-.021M21 12a9 9 0 11-18 0 9 9 0 0118 0zm-9-3.75h.008v.008H12V8.25z',
        ],
        'warning' => [
            'label' => 'Warning',
            'box' => 'border-hazard-700 border-l-hazard bg-hazard-50',
            'icon' => 'M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z',
        ],
        'tip' => [
            'label' => 'Tip',
            'box' => 'border-molten-700 border-l-molten bg-molten-50',
            'icon' => 'M12 18v-5.25m0 0a6.01 6.01 0 001.5-.189m-1.5.189a6.01 6.01 0 01-1.5-.189m3.75 7.478a12.06 12.06 0 01-4.5 0m3.75 2.383a14.406 14.406 0 01-3 0M14.25 18v-.192c0-.983.658-1.823 1.508-2.316a7.5 7.5 0 10-7.517 0c.85.493 1.509 1.333 1.509 2.316V18',
        ],
    ];

    $style = $styles[$config['style'] ?? 'info'] ?? $styles['info'];
@endphp

<div role="note" class="rounded-lg border-2 border-l-8 p-4 {{ $style['box'] }}">
    <p class="flex items-center gap-2 font-bold">
        <svg class="h-5 w-5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" d="{{ $style['icon'] }}" />
        </svg>
        <span>{{ $style['label'] }}</span>
    </p>

    @if (filled($config['heading'] ?? null))
        <h3 class="player-heading mt-2 text-xl">{{ $config['heading'] }}</h3>
    @endif

    {{-- Sanitized at publish time. --}}
    <div class="player-prose mt-2" data-speech-id="html">{!! $config['html'] ?? '' !!}</div>
</div>
