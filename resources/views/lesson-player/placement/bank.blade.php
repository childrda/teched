{{--
    The item bank, shared by both placement renderers.

    Alpine draws the items rather than Blade so that a shuffled bank is
    shuffled in the DOM: tab order, reading order, and what a student sees
    all stay the same sequence. Every item is a real button, so Enter and
    Space pick it up without a keydown handler of our own.
--}}
<div>
    <h4 class="player-field-label" id="bank-heading-{{ $blockId }}">{{ __('placement.bank_heading') }}</h4>
    <p class="mt-1 text-sm text-slate-700">{{ __('placement.bank_hint') }}</p>

    <ul class="mt-3 flex flex-wrap gap-2" aria-labelledby="bank-heading-{{ $blockId }}">
        <template x-for="item in bankItems" :key="item.id">
            <li>
                {{-- Styled from aria-pressed rather than from a class of our
                     own, so what a student sees and what a screen reader is
                     told cannot drift apart. --}}
                <button type="button"
                        class="placement-item"
                        :data-item-id="item.id"
                        :data-speech-id="item.speechId"
                        :aria-pressed="isHolding(item.id) ? 'true' : 'false'"
                        :aria-label="item.name"
                        draggable="true"
                        @dragstart="dragItem(item.id, $event)"
                        @click="toggleItem(item.id)">
                    {{-- Held is carried by a symbol and a heavier border as
                         well as by colour. --}}
                    <span class="placement-mark" aria-hidden="true" x-text="isHolding(item.id) ? '✓' : '+'"></span>
                    <span x-text="item.label"></span>
                </button>
            </li>
        </template>
    </ul>

    <p class="mt-3 text-sm font-semibold text-slate-700" x-show="bankItems.length === 0" x-cloak>
        {{ __('placement.bank_empty') }}
    </p>
</div>
