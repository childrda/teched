@php
    /** @var array<string, mixed> $submission */
    $latest = $submission['latest_review'] ?? null;
    $history = $submission['review_history'] ?? [];
    $possible = $submission['points_possible'];
@endphp

<div class="mt-4 space-y-4 border-t border-slate-200 pt-3">
    @if ($latest && ($latest['reviewed'] ?? false))
        <div class="rounded border border-slate-300 bg-slate-50 p-3 text-sm">
            <p class="font-semibold">{{ __('staff.reviewed') }}
                @if ($latest['reviewed_by'])
                    — {{ __('staff.reviewed_by', ['name' => $latest['reviewed_by']]) }}
                @endif
                @if ($latest['created_at'])
                    — {{ $latest['created_at']->toDayDateTimeString() }}
                @endif
            </p>
            @if (is_array($latest['score'] ?? null))
                <p class="mt-1">
                    {{ __('staff.manual_review_score', [
                        'awarded' => $latest['score']['awarded'],
                        'possible' => $latest['score']['possible'],
                        'percent' => $latest['score']['percentage'],
                    ]) }}
                </p>
            @endif
            @if (filled($latest['comment'] ?? null))
                <div class="mt-3">
                    <p class="font-semibold text-emerald-900">{{ __('staff.feedback_for_student') }}</p>
                    <p class="mt-1 whitespace-pre-wrap">{{ $latest['comment'] }}</p>
                </div>
            @endif
            @if (filled($latest['private_note'] ?? null))
                <div class="mt-3 rounded border border-rose-300 bg-rose-50 p-2">
                    <p class="font-semibold text-rose-900">{{ __('staff.private_note_label') }}</p>
                    <p class="mt-1 whitespace-pre-wrap text-rose-950">{{ $latest['private_note'] }}</p>
                </div>
            @endif
            @if (($latest['review_count'] ?? 1) > 1)
                <p class="mt-2 text-xs text-slate-600">{{ __('staff.earlier_reviews') }} ({{ $latest['review_count'] - 1 }})</p>
            @endif
        </div>
    @endif

    @can('intervene', $attempt)
        <div class="grid gap-4 lg:grid-cols-2">
            <form method="post"
                  action="{{ route('staff.attempts.submissions.review', [$attempt, $submission['id']]) }}"
                  class="space-y-3 rounded border-2 border-slate-400 bg-white p-3">
                @csrf
                <input type="hidden" name="mode" value="review_only">
                <p class="text-sm font-semibold">{{ __('staff.review_only') }}</p>
                <label class="block text-sm">
                    <span class="font-semibold text-emerald-900">{{ __('staff.feedback_for_student') }}</span>
                    <textarea name="comment" rows="3"
                              class="mt-1 block w-full border-2 border-emerald-600 bg-emerald-50 px-2 py-1"
                              maxlength="{{ (int) config('submission-reviews.comment_max', 5000) }}"></textarea>
                </label>
                <label class="block text-sm">
                    <span class="font-semibold text-rose-900">{{ __('staff.private_note_label') }}</span>
                    <textarea name="private_note" rows="3"
                              class="mt-1 block w-full border-2 border-rose-400 bg-rose-50 px-2 py-1"
                              maxlength="{{ (int) config('submission-reviews.comment_max', 5000) }}"></textarea>
                </label>
                <button type="submit" class="player-btn player-btn-primary">
                    {{ __('staff.review_only') }}
                </button>
            </form>

            @if ($possible !== null && $possible > 0)
                <form method="post"
                      action="{{ route('staff.attempts.submissions.review', [$attempt, $submission['id']]) }}"
                      class="space-y-3 rounded border-2 border-slate-400 bg-white p-3">
                    @csrf
                    <input type="hidden" name="mode" value="scored">
                    <p class="text-sm font-semibold">{{ __('staff.review_with_score') }}</p>
                    <label class="block text-sm font-semibold">
                        {{ __('staff.points_awarded') }}
                        <span class="font-normal text-slate-600">
                            — {{ __('staff.points_possible_hint', ['max' => $possible]) }}
                        </span>
                        <input type="number" name="points_awarded" min="0" max="{{ $possible }}" required
                               class="mt-1 block w-24 border-2 border-slate-400 px-2 py-1">
                    </label>
                    <label class="block text-sm">
                        <span class="font-semibold text-emerald-900">{{ __('staff.feedback_for_student') }}</span>
                        <textarea name="comment" rows="3"
                                  class="mt-1 block w-full border-2 border-emerald-600 bg-emerald-50 px-2 py-1"
                                  maxlength="{{ (int) config('submission-reviews.comment_max', 5000) }}"></textarea>
                    </label>
                    <label class="block text-sm">
                        <span class="font-semibold text-rose-900">{{ __('staff.private_note_label') }}</span>
                        <textarea name="private_note" rows="3"
                                  class="mt-1 block w-full border-2 border-rose-400 bg-rose-50 px-2 py-1"
                                  maxlength="{{ (int) config('submission-reviews.comment_max', 5000) }}"></textarea>
                    </label>
                    <button type="submit" class="player-btn player-btn-secondary">
                        {{ __('staff.review_with_score') }}
                    </button>
                </form>
            @elseif ($submission['needs_review'] ?? false)
                <p class="text-sm text-slate-600">
                    This pinned block has no scorable fields; use mark reviewed without a score.
                </p>
            @endif
        </div>
    @endcan

    @if (count($history) > 1)
        <details class="text-sm">
            <summary class="cursor-pointer font-semibold">{{ __('staff.earlier_reviews') }}</summary>
            <ul class="mt-2 space-y-2">
                @foreach (array_slice($history, 1) as $prior)
                    <li class="border border-slate-200 p-2">
                        {{ $prior['created_at']?->toDayDateTimeString() ?? '—' }}
                        — {{ $prior['reviewed_by'] ?? '—' }}
                        @if (is_array($prior['score'] ?? null))
                            — {{ $prior['score']['awarded'] }}/{{ $prior['score']['possible'] }}
                        @endif
                        @if (filled($prior['comment'] ?? null))
                            <p class="mt-1 whitespace-pre-wrap">{{ $prior['comment'] }}</p>
                        @endif
                        @if (filled($prior['private_note'] ?? null))
                            <p class="mt-1 whitespace-pre-wrap text-rose-900">{{ $prior['private_note'] }}</p>
                        @endif
                    </li>
                @endforeach
            </ul>
        </details>
    @endif
</div>
