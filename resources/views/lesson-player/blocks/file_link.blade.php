@php
    $newTab = (bool) ($config['opens_in_new_tab'] ?? false);
@endphp

<div class="player-card">
    <a href="{{ $config['url'] ?? '' }}"
       class="player-btn player-btn-secondary"
       @if ($newTab) target="_blank" rel="noopener noreferrer" @endif>
        <span>{{ $config['label'] ?? 'Open file' }}</span>

        @if ($newTab)
            {{-- Announced to screen readers, drawn for everyone else. --}}
            <span class="sr-only">(opens in a new tab)</span>
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round"
                      d="M13.5 6H5.25A2.25 2.25 0 003 8.25v10.5A2.25 2.25 0 005.25 21h10.5A2.25 2.25 0 0018 18.75V10.5m-10.5 6L21 3m0 0h-5.25M21 3v5.25" />
            </svg>
        @endif
    </a>

    @if (filled($config['description'] ?? null))
        <p class="mt-3 max-w-[68ch] text-base/7">{{ $config['description'] }}</p>
    @endif
</div>
