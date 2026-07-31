{{--
    Sibling of the hotspot map Alpine root. Dispatches the same window event
    the map listens for so both Add controls share addHotspot().
--}}
<div class="pt-2">
    <button
        type="button"
        data-testid="add-hotspot-bottom"
        class="fi-btn relative inline-grid grid-flow-col items-center justify-center gap-1 rounded-lg bg-amber-600 px-2.5 py-1.5 text-sm font-semibold text-white shadow-sm outline-none transition duration-75 focus-visible:ring-2"
        x-data
        x-on:click="$dispatch('teched-add-hotspot')"
    >
        Add hotspot
    </button>
</div>
