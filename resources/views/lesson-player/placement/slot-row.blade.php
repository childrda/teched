{{--
    One numbered row: a description and the button that holds an item for it.

    Matching uses these rows as its only slot layer; image labeling uses them
    as the equivalent to its diagram markers. Both pass the same slot ids, so
    a placement made in either one shows up in the other immediately.

    Expects: $slot, $layer, and optionally $speechId when the description is
    part of the block's read-aloud text.
--}}
@php
    $slotId = \Illuminate\Support\Js::from($slot['id']);
    $layerName = \Illuminate\Support\Js::from($layer);
@endphp

<li class="placement-row">
    {{-- The min width makes the row wrap instead of squeezing the description into a column of single words. --}}
    <p class="flex flex-1 items-start gap-2 text-base/7 sm:min-w-56"
       @isset($speechId) data-speech-id="{{ $speechId }}" @endisset>
        <span class="placement-number" aria-hidden="true">{{ $slot['number'] }}</span>
        <span>{{ $slot['description'] }}</span>
    </p>

    <button type="button"
            class="placement-slot"
            :class="{ 'is-filled': isFilled({{ $slotId }}) }"
            data-slot-id="{{ $slot['id'] }}"
            :aria-label="slotName({{ $slotId }})"
            :draggable="isFilled({{ $slotId }}) ? 'true' : 'false'"
            @dragstart="dragItem(itemIn({{ $slotId }}), $event)"
            @dragover.prevent
            @drop.prevent="dropOnSlot({{ $slotId }}, {{ $layerName }}, $event)"
            @click="activateSlot({{ $slotId }}, {{ $layerName }})">
        <span class="placement-mark" aria-hidden="true" x-text="isFilled({{ $slotId }}) ? '✓' : '+'"></span>
        <span x-text="filledLabel({{ $slotId }}) || {{ \Illuminate\Support\Js::from(__('placement.empty_slot')) }}"></span>
    </button>
</li>
