@php
    $videoTitle = filled($config['title'] ?? null) ? $config['title'] : 'Lesson video';
    $focusQuestions = $config['focus_questions'] ?? [];
@endphp

<div class="player-card space-y-4">
    @if (filled($config['title'] ?? null))
        <h3 class="text-xl font-semibold" data-speech-id="title">{{ $config['title'] }}</h3>
    @endif

    <div class="aspect-video w-full overflow-hidden rounded bg-black">
        <iframe class="h-full w-full"
                src="https://www.youtube-nocookie.com/embed/{{ urlencode($config['video_id'] ?? '') }}"
                title="{{ $videoTitle }}"
                loading="lazy"
                referrerpolicy="strict-origin-when-cross-origin"
                allow="clipboard-write; encrypted-media; picture-in-picture; web-share"
                allowfullscreen></iframe>
    </div>

    @if ($config['captions_available'] ?? false)
        <p class="text-sm text-slate-700">Captions are available in the video player.</p>
    @endif

    @if (filled($config['instructions'] ?? null))
        <p class="text-base/7" data-speech-id="instructions">{{ $config['instructions'] }}</p>
    @endif

    @if ($focusQuestions !== [])
        <div>
            <h4 class="font-semibold">Watch for these</h4>
            <ul class="mt-2 list-disc space-y-1 pl-6 text-base/7">
                @foreach ($focusQuestions as $index => $question)
                    <li data-speech-id="focus_question:{{ $question['id'] ?? $index }}">{{ $question['text'] ?? '' }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    {{-- Sanitized at publish time. Not spoken: it duplicates the audio. --}}
    @if (filled($config['transcript_html'] ?? null))
        <details class="rounded border-2 border-slate-400">
            <summary class="flex min-h-11 cursor-pointer items-center px-3 font-semibold">Transcript</summary>
            <div class="player-prose px-3 pb-3">{!! $config['transcript_html'] !!}</div>
        </details>
    @endif

    {{--
        The only content block that contributes to page completion, and only
        when the author asked for it. The contributor is keyed by block_id,
        so it survives any reordering of the page.
    --}}
    @if ($config['require_confirmation'] ?? false)
        <div class="rounded border-2 border-slate-400 bg-slate-50 p-3"
             x-data="{ confirmed: false }"
             x-init="registerContributor(@js($pageId), {
                 id: @js('video-confirmation:' . $blockId),
                 category: 'confirmation',
                 message: 'Confirm that you have watched the video to continue.',
                 isSatisfied: () => confirmed,
             })">
            <label class="flex min-h-11 cursor-pointer items-center gap-3 font-semibold">
                <input type="checkbox" x-model="confirmed" class="h-6 w-6 rounded border-2 border-slate-600">
                I have watched this video.
            </label>
        </div>
    @endif
</div>
