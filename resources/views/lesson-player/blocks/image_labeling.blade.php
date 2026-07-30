@php
    $activity = \App\Support\PlacementActivity::forImageLabeling(
        $config,
        $blockId,
        $pageId,
        $completionType ?? ''
    );
@endphp

{{--
    Image labeling, drawn twice: markers on the diagram, and a numbered list
    below it. Both layers place into the same slots through the same
    controller, so either one alone is a complete way to finish the activity
    and neither can fall out of step with the other.

    Which item belongs at which point is not in this page: hotspots arrive
    without their answer_id.

    Stays a single column: a two-column split under the player's max-w-4xl
    shrinks the WEL 6.1.1 diagram until hotspots 4 and 7 overlap. Bank still
    precedes targets in the DOM; sticky bank + pickup scroll cover the
    Chromebook co-visibility problem without crowding markers.
--}}
<div class="player-card placement-layout"
     x-data="placementActivity(@js($activity))"
     x-init="captureDisposer(addContributor(@js($pageId), contributor()))"
     @keydown.escape="cancel()">

    @if (filled($config['instructions'] ?? null))
        <p class="text-base/7" data-speech-id="instructions">{{ $config['instructions'] }}</p>
    @endif

    @include('lesson-player.placement.bank', ['blockId' => $blockId])

    <div class="placement-targets">
        {{-- The markers are positioned as percentages of this container, and the
             image fills it, so they hold their places at every width and zoom.
             A diagram that fails to load takes the whole marker layer with it
             rather than leaving buttons floating over nothing; the list below is
             already a complete path, so there is nothing to announce. --}}
        <div>
            <h4 class="player-field-label">{{ __('placement.diagram_heading') }}</h4>

            <div class="placement-diagram mt-3" x-show="imageAvailable">
                <img src="{{ $config['image_url'] ?? '' }}"
                     alt="{{ $config['image_alt'] ?? '' }}"
                     class="block h-auto w-full rounded"
                     data-speech-id="image_alt"
                     {{-- x-on: rather than @, which Blade claims for @error. --}}
                     x-on:error="imageFailed(@js($config['image_url'] ?? ''))">

                <div data-placement-layer="hotspots">
                    @foreach ($activity['slots'] as $slot)
                        @php
                            $slotId = \Illuminate\Support\Js::from($slot['id']);
                        @endphp

                        <button type="button"
                                class="placement-hotspot"
                                :class="{ 'is-filled': isFilled({{ $slotId }}) }"
                                data-slot-id="{{ $slot['id'] }}"
                                style="left: {{ $slot['x'] }}%; top: {{ $slot['y'] }}%"
                                :aria-label="slotName({{ $slotId }})"
                                :draggable="isFilled({{ $slotId }}) ? 'true' : 'false'"
                                @dragstart="dragItem(itemIn({{ $slotId }}), $event)"
                                @dragend="dragEnded()"
                                @dragover.prevent
                                @drop.prevent="dropOnSlot({{ $slotId }}, 'hotspots', $event)"
                                @click="activateSlot({{ $slotId }}, 'hotspots')">
                            <span class="placement-number" aria-hidden="true">{{ $slot['number'] }}</span>

                            {{-- The full label lives in the list below; a marker
                                 shows as much of it as it can without growing
                                 over its neighbours. --}}
                            <span class="placement-hotspot-label"
                                  x-show="isFilled({{ $slotId }})"
                                  x-text="filledLabel({{ $slotId }})"></span>
                        </button>
                    @endforeach
                </div>
            </div>
        </div>

        {{-- A long description is content, not a screen-reader afterthought:
             every student can open it. --}}
        @if (filled($config['long_description'] ?? null))
            <details class="rounded border-2 border-slate-400">
                <summary class="flex min-h-11 cursor-pointer items-center px-3 font-semibold">
                    {{ __('placement.image_description') }}
                </summary>
                <p class="px-3 pb-3 text-base/7" data-speech-id="long_description">{{ $config['long_description'] }}</p>
            </details>
        @endif

        {{-- Always rendered, on every screen size, and never hidden from
             assistive technology. It is the working path for keyboard and screen
             reader users, for narrow screens and high zoom, for anyone who
             cannot place a pointer precisely, and for any session where the
             image did not load. --}}
        <div data-placement-layer="list">
            <h4 class="player-field-label" id="points-heading-{{ $blockId }}">{{ __('placement.points_heading') }}</h4>

            <ol class="mt-3 space-y-3" aria-labelledby="points-heading-{{ $blockId }}">
                @foreach ($activity['slots'] as $slot)
                    @include('lesson-player.placement.slot-row', [
                        'slot' => $slot,
                        'layer' => 'list',
                    ])
                @endforeach
            </ol>
        </div>
    </div>

    @include('lesson-player.placement.status')
</div>
