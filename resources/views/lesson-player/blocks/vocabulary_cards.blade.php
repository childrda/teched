@php
    $revealOnTap = ($config['reveal_mode'] ?? 'tap') === 'tap';
    $terms = $config['terms'] ?? [];
@endphp

<ul class="grid gap-4 sm:grid-cols-2">
    @foreach ($terms as $index => $term)
        @php
            // The DOM id is built from the block, not the author's term id,
            // which is free text and may not be a valid identifier.
            $analogyId = "analogy-{$blockId}-{$index}";
            $termId = $term['id'] ?? $index;
        @endphp

        <li class="player-card">
            <h3 class="player-heading text-xl" data-speech-id="{{ $termId }}:term">{{ $term['term'] ?? '' }}</h3>

            <p class="mt-1 text-base/7" data-speech-id="{{ $termId }}:definition">{{ $term['definition'] ?? '' }}</p>

            @if (filled($term['analogy'] ?? null))
                @if ($revealOnTap)
                    <div class="mt-3" x-data="{ open: false }">
                        <button type="button"
                                class="player-btn player-btn-quiet player-btn-sm"
                                aria-controls="{{ $analogyId }}"
                                :aria-expanded="open ? 'true' : 'false'"
                                @click="open = ! open"
                                x-text="open ? 'Hide analogy' : 'Show analogy'"></button>

                        <p id="{{ $analogyId }}"
                           x-show="open"
                           class="mt-2 text-base/7"
                           data-speech-id="{{ $termId }}:analogy">{{ $term['analogy'] }}</p>
                    </div>
                @else
                    <p class="mt-3 text-base/7" data-speech-id="{{ $termId }}:analogy">
                        <span class="font-semibold">Think of it like:</span> {{ $term['analogy'] }}
                    </p>
                @endif
            @endif
        </li>
    @endforeach
</ul>
