@php
    $imageUrl = $get('../image_url') ?? $get('image_url') ?? '';
    $hotspots = $get('../hotspots') ?? $get('hotspots') ?? [];
    $bank = $get('../bank') ?? $get('bank') ?? [];
@endphp

<div
    wire:key="hotspot-map-{{ md5((string) $imageUrl) }}"
    x-data="hotspotEditor({
        imageUrl: @js($imageUrl),
        hotspots: @js($hotspots),
        bank: @js($bank),
    })"
    x-init="
        $watch('hotspots', (value) => {
            const path = @js($getStatePath());
            // hotspot_map is dehydrated(false); write sibling hotspots instead.
            const hotspotsPath = path.replace(/\.hotspot_map$/, '.hotspots');
            $wire.set(hotspotsPath, value);
        }, { deep: true })
    "
    class="space-y-3 rounded-lg border border-gray-300 p-3 dark:border-gray-600"
>
    <div class="flex flex-wrap items-center gap-2">
        <button type="button"
                class="fi-btn relative grid-flow-col items-center justify-center font-semibold outline-none transition duration-75 focus-visible:ring-2 rounded-lg fi-btn-color-primary fi-btn-size-sm fi-btn-outline gap-1 px-2.5 py-1.5 text-sm inline-grid shadow-sm bg-amber-600 text-white"
                @click="addHotspot()">
            Add hotspot
        </button>
        <p class="text-sm text-gray-600 dark:text-gray-300">
            Click the image to place the selected hotspot. Arrow keys nudge 0.5%; Shift+Arrow nudges 2%.
            Numeric X%/Y% inputs below stay the precision path.
        </p>
    </div>

    <template x-if="imageFailed || ! imageUrl">
        <p class="rounded-md border border-amber-600 bg-amber-50 p-3 text-sm text-amber-950" role="status">
            Image could not be loaded. Use the numeric X%/Y% inputs below as the precision path.
        </p>
    </template>

    <div class="relative inline-block max-w-full" x-show="imageUrl && ! imageFailed" x-cloak>
        <img
            x-ref="image"
            :src="imageUrl"
            alt="Diagram for placing hotspots"
            class="block max-h-96 max-w-full"
            draggable="false"
            @error="onImageError()"
            @click="onImageClick($event)"
        />
        <template x-for="(hotspot, index) in hotspots" :key="hotspot.id">
            <button
                type="button"
                class="absolute flex h-8 w-8 -translate-x-1/2 -translate-y-1/2 items-center justify-center rounded-full border-2 border-white bg-amber-700 text-xs font-bold text-white shadow"
                :class="selectedIndex === index ? 'ring-2 ring-amber-300' : ''"
                :style="`left: ${hotspot.x_pct}%; top: ${hotspot.y_pct}%;`"
                :aria-label="`Hotspot ${hotspot.number}`"
                @pointerdown="startDrag(index, $event)"
                @keydown="onMarkerKeydown(index, $event)"
                @focus="select(index)"
                x-text="hotspot.number"
            ></button>
        </template>
    </div>

    <p class="sr-only" role="status" aria-live="polite" x-text="announcement"></p>
</div>
