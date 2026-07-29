<figure class="player-card">
    <img src="{{ $config['url'] ?? '' }}"
         alt="{{ $config['alt'] ?? '' }}"
         data-speech-id="alt"
         loading="lazy"
         class="mx-auto h-auto max-w-full rounded">

    @if (filled($config['caption'] ?? null))
        <figcaption class="mt-3 text-sm text-slate-700">{{ $config['caption'] }}</figcaption>
    @endif

    {{-- A long description is content, not a screen-reader afterthought:
         every student can open it. --}}
    @if (filled($config['long_description'] ?? null))
        <details class="mt-3 rounded border-2 border-slate-400">
            <summary class="flex min-h-11 cursor-pointer items-center px-3 font-semibold">
                Image description
            </summary>
            <p class="px-3 pb-3 text-base/7" data-speech-id="long_description">{{ $config['long_description'] }}</p>
        </details>
    @endif
</figure>
