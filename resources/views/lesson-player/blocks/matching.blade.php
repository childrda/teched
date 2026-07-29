@php
    $activity = \App\Support\PlacementActivity::forMatching(
        $config,
        $blockId,
        $pageId,
        $completionType ?? ''
    );
@endphp

{{--
    Matching. Which item belongs in which row is not in this page: the slots
    arrive without their answer_id, so the markup can only say what a student
    can already see.

    x-init registers the activity's one completion contributor through the
    player's own method, which hands back the registry's remove handle;
    captureDisposer() stores it and returns nothing, because an x-init
    expression that evaluates to a function has Alpine call it.
--}}
<div class="player-card space-y-5"
     x-data="placementActivity(@js($activity))"
     x-init="captureDisposer(addContributor(@js($pageId), contributor()))"
     @keydown.escape="cancel()">

    @if (filled($config['instructions'] ?? null))
        <p class="text-base/7" data-speech-id="instructions">{{ $config['instructions'] }}</p>
    @endif

    @include('lesson-player.placement.bank', ['blockId' => $blockId])

    <div data-placement-layer="rows">
        <h4 class="player-field-label" id="rows-heading-{{ $blockId }}">{{ __('placement.rows_heading') }}</h4>

        <ol class="mt-3 space-y-3" aria-labelledby="rows-heading-{{ $blockId }}">
            @foreach ($activity['slots'] as $slot)
                @include('lesson-player.placement.slot-row', [
                    'slot' => $slot,
                    'layer' => 'rows',
                    'speechId' => $slot['id'] . ':description',
                ])
            @endforeach
        </ol>
    </div>

    @include('lesson-player.placement.status')
</div>
