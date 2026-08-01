{{--
    One block. The wrapper owns the read-aloud affordance so no renderer has
    to; the renderer only marks which element belongs to which speech segment
    with data-speech-id.
--}}
<div class="lesson-block" data-block-id="{{ $blockId }}" data-block-type="{{ $type }}">
    @if ($speech !== [])
        {{-- x-if, not x-show: with no speechSynthesis the button is never
             rendered at all, rather than rendered and disabled. --}}
        <template x-if="speech.supported">
            <div class="mb-2 flex flex-wrap justify-end gap-2">
                {{-- Read aloud picks up where this block was stopped, so the
                     way back to the beginning has to be its own control. It
                     appears only while there is a position to abandon.

                     Not "Start over": the matching and image-labeling
                     activities already use that wording for resetting the
                     activity itself, and the two controls can sit on one
                     page. --}}
                <template x-if="hasResumePoint(@js($blockId))">
                    <button type="button"
                            class="player-btn player-btn-quiet player-btn-sm"
                            @click="startOverReading(@js($blockId))">
                        Read from start
                    </button>
                </template>

                <button type="button"
                        class="player-btn player-btn-quiet player-btn-sm"
                        @click="toggleReadAloud(@js($blockId))"
                        x-text="isReading(@js($blockId)) ? 'Stop reading' : 'Read aloud'">
                </button>
            </div>
        </template>
    @endif

    @include($partial, [
        'block' => $block,
        'config' => $config,
        'blockId' => $blockId,
        'pageId' => $pageId,
        'completionType' => $completionType,
    ])
</div>
