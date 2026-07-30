@php
    /** @var array<string, mixed> $result */
@endphp
<div class="mt-2 rounded border border-slate-300 p-3 text-sm">
    @if ($result['score'] !== null)
        <p>
            {{ __('staff.score', [
                'score' => $result['score'],
                'max' => $result['max_score'],
                'percent' => $result['percentage'] ?? 0,
            ]) }}
            — {{ $result['passed'] ? __('staff.passed') : __('staff.not_passed') }}
        </p>
    @elseif ($result['requires_manual_review'])
        <p>{{ __('staff.needs_review') }}</p>
    @endif

    @if (count($result['details']) > 0)
        <ul class="mt-2 space-y-2">
            @foreach ($result['details'] as $item)
                <li class="border border-slate-200 p-2">
                    <p class="font-semibold">
                        {{ $item['item_id'] }} —
                        {{ $item['correct'] ? __('staff.item_correct') : __('staff.item_incorrect') }}
                    </p>
                    <p>{{ __('staff.chosen_answer') }}: {{ is_scalar($item['chosen']) ? $item['chosen'] : json_encode($item['chosen']) }}</p>
                    <p>{{ __('staff.correct_answer') }}: {{ is_scalar($item['correct_answer']) ? $item['correct_answer'] : json_encode($item['correct_answer']) }}</p>
                    @if ($item['feedback'])
                        <p>{{ $item['feedback'] }}</p>
                    @endif
                </li>
            @endforeach
        </ul>
    @endif
</div>
