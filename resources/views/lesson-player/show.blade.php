<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    @include('partials.favicon')
    <title>{{ $manifest['title'] }}{{ ! empty($preview) ? ' (Preview)' : '' }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="h-full bg-steel-100 font-sans text-steel-950">

@php
    $capabilities = $capabilities ?? \App\Support\PlayerCapabilities::forPlay();
    $preview = $preview ?? false;
@endphp

<noscript>
    <p class="player-notice m-4 font-semibold">
        This lesson needs JavaScript turned on. Please enable JavaScript and reload the page.
    </p>
</noscript>

@if ($preview)
    <div class="player-notice rounded-none border-x-0 border-t-0 px-4 py-3 text-sm font-semibold" role="status">
        {{ $previewBanner ?? 'Previewing your last saved draft. Grading, persistence, and completion-gate enforcement are not being tested.' }}
    </div>
@endif

{{-- The whole manifest is embedded once, escaped by @js. The player never fetches.
     data-lesson-code lets nested block components build the grading URL
     without changing the Block view component. Capabilities are the only
     branch for persist/grade/nav — never preview mode or token presence. --}}
<div x-data="lessonPlayer(@js($manifest), @js($attempt), @js($capabilities))"
     data-lesson-code="{{ $manifest['code'] }}"
     x-cloak
     class="flex min-h-full flex-col">

    <header class="player-header">
        <div class="mx-auto flex w-full max-w-4xl flex-col gap-3 px-4 py-3 sm:flex-row sm:items-center sm:justify-between">
            <div class="min-w-0">
                <h1 class="player-heading truncate text-xl sm:text-2xl">{{ $manifest['title'] }}</h1>
                {{-- Numbered page breadcrumb: reached pages are buttons; the
                     current page is a non-interactive current marker; unreached
                     (and back-locked) pages are inert. goToBreadcrumb() enforces
                     the watermark — styling alone is not the gate. --}}
                <nav x-show="totalPages > 0"
                     x-cloak
                     class="player-breadcrumb"
                     aria-label="Lesson pages">
                    <ol class="player-breadcrumb-list">
                        <template x-for="index in pageIndexes" :key="index">
                            <li class="player-breadcrumb-item">
                                <template x-if="isBreadcrumbCurrent(index)">
                                    <span class="player-breadcrumb-step player-breadcrumb-step-current"
                                          aria-current="page"
                                          x-text="index + 1"></span>
                                </template>
                                <template x-if="! isBreadcrumbCurrent(index) && canGoToBreadcrumb(index)">
                                    <button type="button"
                                            class="player-breadcrumb-step player-breadcrumb-step-reached"
                                            :aria-label="`Go to page ${index + 1}`"
                                            @click="goToBreadcrumb(index)"
                                            x-text="index + 1"></button>
                                </template>
                                <template x-if="! isBreadcrumbCurrent(index) && ! canGoToBreadcrumb(index)">
                                    <span class="player-breadcrumb-step player-breadcrumb-step-unreached"
                                          aria-disabled="true"
                                          x-text="index + 1"></span>
                                </template>
                            </li>
                        </template>
                    </ol>
                </nav>
                <p class="player-header-meta text-sm" x-show="readOnly" x-cloak>{{ __('player.read_only') }}</p>
                <p class="player-header-meta text-sm font-semibold"
                   role="status"
                   aria-live="polite"
                   x-show="! readOnly"
                   x-text="syncMessage"
                   x-cloak></p>
            </div>

            <div class="flex flex-wrap items-center gap-3">
                {{-- Shared Chromebook carts: an obvious logout matters more
                     than any session lifetime setting. POST + CSRF, not a link. --}}
                <x-auth.session />

                {{-- No speech controls at all when the browser cannot speak. --}}
                <template x-if="speech.supported">
                    <div class="relative flex flex-wrap items-center gap-2">
                        <template x-if="speech.speaking">
                            <div class="flex items-center gap-2">
                                <button type="button"
                                        class="player-btn player-btn-quiet player-btn-sm"
                                        @click="speech.paused ? resumeReading() : pauseReading()"
                                        x-text="speech.paused ? 'Resume' : 'Pause'"></button>
                                <button type="button"
                                        class="player-btn player-btn-quiet player-btn-sm"
                                        @click="stopReading()">Stop</button>
                            </div>
                        </template>

                        <button type="button"
                                class="player-btn player-btn-quiet player-btn-sm"
                                id="speech-settings-toggle"
                                x-ref="settingsToggle"
                                aria-controls="speech-settings"
                                :aria-expanded="settingsOpen ? 'true' : 'false'"
                                @click="settingsOpen = ! settingsOpen">
                            Read-aloud settings
                        </button>

                        <div id="speech-settings"
                             x-show="settingsOpen"
                             @click.outside="settingsOpen = false"
                             @keydown.escape="settingsOpen = false; $refs.settingsToggle?.focus()"
                             role="group"
                             aria-labelledby="speech-settings-toggle"
                             class="absolute top-full right-0 z-20 mt-2 w-72 space-y-4 rounded-lg border-2 border-steel-400 bg-white p-4 text-steel-950 shadow-lg">
                            <div>
                                <label class="player-field-label" for="speech-rate">
                                    Speed <span x-text="`${speech.rate.toFixed(1)}×`"></span>
                                </label>
                                <input id="speech-rate"
                                       type="range"
                                       min="0.5"
                                       max="2"
                                       step="0.1"
                                       class="mt-2 h-11 w-full"
                                       x-model.number="speech.rate"
                                       @input="applyRate()">
                            </div>

                            <div>
                                <label class="player-field-label" for="speech-voice">Voice</label>
                                <select id="speech-voice"
                                        class="mt-2 min-h-11 w-full rounded-md border-2 border-steel-700 bg-white px-2"
                                        x-model="speech.voiceUri"
                                        @change="applyVoice()">
                                    <option value="">Default voice</option>
                                    <template x-for="voice in speech.voices" :key="voice.voiceURI">
                                        <option :value="voice.voiceURI" x-text="voice.label"></option>
                                    </template>
                                </select>
                            </div>
                        </div>
                    </div>
                </template>
            </div>
        </div>
    </header>

    <main x-ref="main" class="mx-auto w-full max-w-4xl flex-1 px-4 py-6">
        @foreach ($manifest['pages'] as $index => $page)
            <section x-show="currentIndex === {{ $index }}"
                     data-page-index="{{ $index }}"
                     aria-labelledby="page-heading-{{ $index }}">
                <h2 id="page-heading-{{ $index }}"
                    data-page-heading
                    tabindex="-1"
                    class="player-heading text-3xl sm:text-4xl">{{ $page['title'] }}</h2>

                <div class="mt-6 space-y-8">
                    @foreach ($page['blocks'] as $block)
                        <x-player.block :block="$block"
                                        :page-id="$page['page_id']"
                                        :completion-type="$page['completion_type']" />
                    @endforeach
                </div>
            </section>
        @endforeach
    </main>

    {{-- data-drag-scroll-inset-bottom: drag auto-scroll measures this at each
         dragstart so the edge zone sits above the sticky footer, including when
         gateMessage grows or shrinks the bar. --}}
    <footer class="player-footer sticky bottom-0"
            data-drag-scroll-inset-bottom>
        <nav aria-label="Lesson navigation" class="mx-auto w-full max-w-4xl px-4 py-3">
            {{-- Why Continue is not yet available: visible text, not colour. --}}
            <p id="continue-hint"
               class="min-h-6 text-sm font-semibold text-hazard-700"
               aria-live="polite"
               x-text="gateMessage"></p>

            <div class="mt-2 flex flex-wrap items-center gap-3">
                <template x-if="canGoBack">
                    <button type="button" class="player-btn player-btn-secondary" @click="goBack()">Back</button>
                </template>

                <div class="flex-1"></div>

                <template x-if="allowSkip && ! isLastPage">
                    <button type="button" class="player-btn player-btn-secondary" @click="skip()">Skip</button>
                </template>

                <template x-if="! isLastPage">
                    <button type="button"
                            class="player-btn player-btn-primary"
                            :aria-disabled="canContinue ? 'false' : 'true'"
                            aria-describedby="continue-hint"
                            @click="goForward()"
                            x-text="continueLabel">Continue</button>
                </template>

                <template x-if="isLastPage">
                    <p class="text-sm font-semibold text-steel-700">End of lesson.</p>
                </template>
            </div>
        </nav>
    </footer>

    {{-- Page changes are announced here; the region stays in the DOM so
         assistive technology is already watching it. --}}
    <p class="sr-only" role="status" aria-live="polite" x-text="announcement"></p>
</div>

</body>
</html>
