@php
    // hotspot_map sits on block data; bank/hotspots/image_url are siblings.
    $imageUrl = $get('image_url') ?? '';
    $hotspots = $get('hotspots') ?? [];
    $bank = $get('bank') ?? [];
@endphp

<div
    wire:key="hotspot-map-{{ md5((string) $imageUrl) }}"
    x-data="hotspotEditor({
        imageUrl: @js($imageUrl),
        hotspots: @js($hotspots),
        bank: @js($bank),
    })"
    x-on:teched-add-hotspot.window="addHotspot()"
    x-init="
        $watch('hotspots', (value) => {
            const path = @js($getStatePath());
            // hotspot_map is dehydrated(false); write sibling hotspots instead.
            const hotspotsPath = path.replace(/\.hotspot_map$/, '.hotspots');
            $wire.set(hotspotsPath, value);
        }, { deep: true })
    "
    class="space-y-3 rounded-lg border border-gray-300 p-3 dark:border-gray-600 lg:sticky lg:top-6 lg:self-start"
>
    <div class="flex flex-wrap items-center gap-2">
        <button type="button"
                data-testid="add-hotspot-top"
                class="fi-btn relative grid-flow-col items-center justify-center font-semibold outline-none transition duration-75 focus-visible:ring-2 rounded-lg fi-btn-color-primary fi-btn-size-sm fi-btn-outline gap-1 px-2.5 py-1.5 text-sm inline-grid shadow-sm bg-amber-600 text-white"
                x-on:click="addHotspot()">
            Add hotspot
        </button>
        <button type="button"
                class="fi-btn relative grid-flow-col items-center justify-center font-semibold outline-none transition duration-75 focus-visible:ring-2 rounded-lg fi-btn-size-sm fi-btn-outline gap-1 px-2.5 py-1.5 text-sm inline-grid shadow-sm border border-gray-400 bg-white text-gray-800 dark:bg-gray-800 dark:text-gray-100"
                x-on:click="removeSelectedHotspot()"
                x-bind:disabled="selectedIndex < 0">
            Remove selected
        </button>
        <p class="text-sm text-gray-600 dark:text-gray-300">
            Click the image to place the selected hotspot (highlighted). Arrow keys nudge 0.5%; Shift+Arrow nudges 2%.
            Numeric X%/Y% inputs stay the precision path.
        </p>
    </div>

    <template x-if="imageFailed || ! imageUrl">
        <p class="rounded-md border border-amber-600 bg-amber-50 p-3 text-sm text-amber-950" role="status">
            Image could not be loaded. Use the numeric X%/Y% inputs as the precision path.
        </p>
    </template>

    <div
        data-testid="hotspot-canvas"
        class="relative inline-block w-full max-w-full"
        x-show="imageUrl && ! imageFailed"
        x-cloak
    >
        <img
            x-ref="image"
            :src="imageUrl"
            alt="Diagram for placing hotspots"
            class="block h-auto max-h-[36rem] w-full max-w-full object-contain"
            draggable="false"
            x-on:error="onImageError()"
            x-on:click="onImageClick($event)"
        />
        <template x-for="(hotspot, index) in hotspots" :key="hotspot.id">
            <button
                type="button"
                class="absolute flex h-8 w-8 -translate-x-1/2 -translate-y-1/2 items-center justify-center rounded-full border-2 text-xs font-bold text-white shadow"
                :class="selectedIndex === index
                    ? 'z-10 scale-125 border-sky-300 bg-sky-700 ring-4 ring-sky-300 ring-offset-2'
                    : 'border-white bg-amber-700'"
                :aria-current="selectedIndex === index ? 'true' : 'false'"
                :style="`left: ${hotspot.x_pct}%; top: ${hotspot.y_pct}%;`"
                :aria-label="selectedIndex === index ? `Selected hotspot ${hotspot.number}` : `Hotspot ${hotspot.number}`"
                x-on:pointerdown="startDrag(index, $event)"
                x-on:keydown="onMarkerKeydown(index, $event)"
                x-on:focus="select(index)"
                x-on:click.stop="select(index)"
                x-text="hotspot.number"
            ></button>
        </template>
    </div>

    <p class="sr-only" role="status" aria-live="polite" x-text="announcement"></p>
</div>
