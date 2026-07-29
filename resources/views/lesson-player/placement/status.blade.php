{{--
    Reset, and the live region every state change is reported through.

    Reset is a plain button: nothing is stored, so there is nothing to
    confirm. The region stays in the DOM whether or not it has anything to
    say, so assistive technology is already watching it.
--}}
<div class="flex flex-wrap items-center gap-3 border-t-2 border-slate-300 pt-4">
    <button type="button" class="player-btn player-btn-quiet player-btn-sm" @click="resetActivity()">
        {{ __('placement.reset_activity') }}
    </button>
</div>

<div class="sr-only" aria-live="polite" aria-atomic="true" x-text="announcement"></div>
