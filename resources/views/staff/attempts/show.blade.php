@php
    /** @var array<string, mixed> $detail */
    $attempt = $detail['attempt'];

    // Label the blocked blocks from the same rows the sections below render,
    // so the summary names exactly what the teacher will read further down.
    $blockedBlockIds = $detail['blocked_block_ids'];
    $blockedLabels = collect($detail['blocks'])
        ->whereIn('block_id', $blockedBlockIds)
        ->pluck('block_type')
        ->all();
@endphp

@extends('layouts.staff')

@section('title', __('staff.attempt_title'))
@section('heading', $detail['student_name'])
@section('intro', $detail['lesson_title'].' ('.$detail['lesson_code'].')')

@section('nav')
    @if ($attempt->assignment)
        <a href="{{ route('staff.assignments.show', $attempt->assignment) }}" class="underline">{{ __('staff.back_assignment') }}</a>
    @endif
@endsection

@section('content')
    <section class="mb-8 rounded-lg border-2 border-steel-400 bg-white p-4" aria-labelledby="attempt-context">
        <h2 id="attempt-context" class="player-heading text-xl">{{ __('staff.attempt_title') }}</h2>
        <dl class="mt-3 grid gap-2 sm:grid-cols-2">
            <div>
                <dt class="text-sm font-semibold text-steel-700">{{ __('staff.column_status') }}</dt>
                <dd>{{ $detail['status_label'] }}
                    @if ($detail['blocked'])
                        — {{ $blockedLabels === []
                            ? __('staff.blocked')
                            : __('staff.blocked_on', ['blocks' => implode(', ', $blockedLabels)]) }}
                    @endif
                </dd>
            </div>
            <div>
                <dt class="text-sm font-semibold text-steel-700">{{ __('staff.pinned_version', ['number' => $detail['version_number']]) }}</dt>
                <dd>{{ $detail['class_name'] ?? '—' }}</dd>
            </div>
            <div>
                <dt class="text-sm font-semibold text-steel-700">{{ __('staff.current_page') }}</dt>
                <dd>{{ $detail['current_page_position'] ?? '—' }}
                    @if ($detail['current_page_title'])
                        — {{ $detail['current_page_title'] }}
                    @endif
                </dd>
            </div>
            <div>
                <dt class="text-sm font-semibold text-steel-700">{{ __('staff.active_time') }}</dt>
                <dd>{{ gmdate('H:i:s', (int) $detail['active_seconds']) }}</dd>
            </div>
            <div>
                <dt class="text-sm font-semibold text-steel-700">{{ __('staff.started_at') }}</dt>
                <dd>{{ \App\Support\DisplayTime::toDayDateTimeString($detail['started_at']) }}</dd>
            </div>
            <div>
                <dt class="text-sm font-semibold text-steel-700">{{ __('staff.completed_at') }}</dt>
                <dd>{{ \App\Support\DisplayTime::toDayDateTimeString($detail['completed_at']) }}</dd>
            </div>
            @if ($detail['superseded_at'])
                <div>
                    <dt class="text-sm font-semibold text-steel-700">{{ __('staff.superseded_at') }}</dt>
                    <dd>{{ \App\Support\DisplayTime::toDayDateTimeString($detail['superseded_at']) }}
                        @if ($detail['superseded_by'])
                            ({{ $detail['superseded_by'] }})
                        @endif
                    </dd>
                </div>
            @endif
            @if ($detail['time_to_complete_seconds'] !== null)
                <div>
                    <dt class="text-sm font-semibold text-steel-700">{{ __('staff.time_to_complete') }}</dt>
                    <dd>{{ gmdate('H:i:s', (int) $detail['time_to_complete_seconds']) }}</dd>
                </div>
            @endif
        </dl>

        @can('intervene', $attempt)
            <div class="mt-4 flex flex-wrap gap-4">
                @if ($attempt->status->value === 'completed')
                    <a class="player-btn player-btn-primary"
                       href="{{ route('staff.attempts.reopen.confirm', $attempt) }}">
                        {{ __('staff.reopen_attempt', ['student' => $detail['student_name']]) }}
                    </a>
                @endif
                @if (in_array($attempt->status->value, ['in_progress', 'completed'], true))
                    <a class="player-btn player-btn-secondary"
                       href="{{ route('staff.attempts.restart.confirm', $attempt) }}">
                        {{ __('staff.restart_lesson', ['student' => $detail['student_name']]) }}
                    </a>
                @endif
            </div>
        @endcan
    </section>

    @foreach ($detail['blocks'] as $block)
        @php $blockIsBlocked = in_array($block['block_id'], $blockedBlockIds, true); @endphp
        <section @class([
                     'mb-8 rounded-lg border-2 bg-white p-4',
                     'border-red-700' => $blockIsBlocked,
                     'border-steel-400' => ! $blockIsBlocked,
                 ])
                 aria-labelledby="block-{{ $block['block_id'] }}">
            <h2 id="block-{{ $block['block_id'] }}" class="player-heading text-xl">
                {{ $block['block_type'] }}
                <span class="player-meta text-sm font-normal text-steel-700">({{ $block['block_id'] }})</span>
                @if ($blockIsBlocked)
                    <span class="ml-1 inline-block rounded border-2 border-red-700 bg-red-50 px-2 py-0.5 text-sm font-semibold text-red-900">
                        {{ __('staff.blocked_badge') }}
                    </span>
                @endif
                @if ($block['needs_review'])
                    — {{ __('staff.needs_review') }}
                @endif
            </h2>
            @if ($block['page_title'])
                <p class="text-sm text-steel-700">{{ $block['page_title'] }}</p>
            @endif

            @if ($block['attempts'])
                <p class="player-meta mt-2 text-sm text-steel-700">
                    @if ($block['attempts']['allowed'] === null)
                        {{ __('staff.attempts_unlimited', ['used' => $block['attempts']['used']]) }}
                    @else
                        {{ __('staff.attempts_used', ['used' => $block['attempts']['used'], 'allowed' => $block['attempts']['allowed']]) }}
                    @endif
                </p>
            @endif

            {{-- Content-only blocks never hold student state, so a draft
                 section for them is several screens of "no saved draft" between
                 a teacher and the work they came to read. holds_state is the
                 presenter's own flag for exactly this. --}}
            @if ($block['holds_state'])
                <div class="mt-4 border-t-2 border-steel-300 pt-4">
                    <h3 class="player-heading text-lg">{{ __('staff.current_work') }}</h3>
                    @if (! $block['current_work']['has_state'])
                        <p class="mt-2 text-sm text-steel-700">{{ __('staff.current_work_empty') }}</p>
                    @else
                        <p class="player-meta mt-2 text-sm text-steel-700">
                            {{ __('staff.current_work_updated', ['when' => \App\Support\DisplayTime::toDayDateTimeString($block['current_work']['updated_at'] ?? null)]) }}
                        </p>
                        @if ($block['current_work']['differs_from_latest_submission'])
                            <p class="mt-1 text-sm font-semibold text-steel-950">{{ __('staff.draft_differs') }}</p>
                        @elseif (count($block['submitted_history']) > 0)
                            <p class="mt-1 text-sm text-steel-700">{{ __('staff.draft_matches') }}</p>
                        @endif
                        @include('staff.attempts.partials.response', [
                            'formatted' => $block['current_work']['formatted'],
                        ])
                    @endif
                </div>
            @endif

            {{-- Same gate, plus a floor: if rows exist for a block whose type
                 no longer claims to hold state, they are still shown rather
                 than silently dropped. --}}
            @if ($block['holds_state'] || count($block['submitted_history']) > 0)
                <div class="mt-4 border-t-2 border-steel-300 pt-4">
                    <h3 class="player-heading text-lg">{{ __('staff.submitted_history') }}</h3>
                    @if (count($block['submitted_history']) === 0)
                        <p class="mt-2 text-sm text-steel-700">{{ __('staff.submitted_history_empty') }}</p>
                    @else
                        <ul class="mt-3 space-y-4">
                            @foreach ($block['submitted_history'] as $submission)
                                <li @class([
                                    'rounded-lg border p-3',
                                    'border-arc border-2' => $submission['is_latest_submission'] ?? false,
                                    'border-steel-300' => ! ($submission['is_latest_submission'] ?? false),
                                ])>
                                    <p class="font-semibold text-steel-950">
                                        {{ __('staff.submission_number', ['number' => $submission['attempt_number']]) }}
                                        — {{ __('staff.submitted_at', ['when' => \App\Support\DisplayTime::toDayDateTimeString($submission['submitted_at'] ?? null)]) }}
                                        @if ($submission['needs_review'] ?? false)
                                            — {{ __('staff.awaiting_review') }}
                                        @elseif ($submission['requires_manual_review'] && ($submission['latest_review']['reviewed'] ?? false))
                                            — {{ __('staff.reviewed') }}
                                        @endif
                                    </p>
                                    @if ($submission['score'] !== null)
                                        <p class="player-meta text-sm text-steel-700">
                                            {{ __('staff.score', [
                                                'score' => $submission['score'],
                                                'max' => $submission['max_score'],
                                                'percent' => $submission['max_score'] ? round(($submission['score'] / $submission['max_score']) * 100) : 0,
                                            ]) }}
                                            — {{ $submission['passed'] ? __('staff.passed') : __('staff.not_passed') }}
                                        </p>
                                    @endif
                                    @include('staff.attempts.partials.response', [
                                        'formatted' => $submission['formatted'],
                                    ])

                                    @if ($submission['requires_manual_review'])
                                        @include('staff.attempts.partials.manual-review', [
                                            'attempt' => $attempt,
                                            'submission' => $submission,
                                        ])
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </div>
            @endif

            @if (count($block['all_results']) > 0)
                <div class="mt-4 border-t-2 border-steel-300 pt-4">
                    <h3 class="player-heading text-lg">{{ __('staff.grading') }}</h3>
                    @if ($block['emphasize_first'] && $block['first_result'])
                        <p class="mt-2 text-sm font-semibold text-steel-700">{{ __('staff.first_result') }}</p>
                        @include('staff.attempts.partials.teacher-result', ['result' => $block['first_result']])
                    @endif
                    @if ($block['latest_result'])
                        <p class="mt-4 text-sm font-semibold text-steel-700">{{ __('staff.latest_result') }}</p>
                        @include('staff.attempts.partials.teacher-result', ['result' => $block['latest_result']])
                    @endif
                </div>
            @endif

            @can('intervene', $attempt)
                @if ($block['auto_gradable'])
                    <form method="post" action="{{ route('staff.attempts.grant-retries', $attempt) }}" class="mt-4 space-y-2 border-t-2 border-steel-300 pt-4">
                        @csrf
                        <input type="hidden" name="block_id" value="{{ $block['block_id'] }}">
                        <label class="block text-sm font-semibold text-steel-700">
                            {{ __('staff.additional_attempts') }}
                            <input type="number" name="additional_attempts" value="1" min="1" max="20"
                                   class="mt-1 block w-24 rounded border-2 border-steel-700 px-2 py-1">
                        </label>
                        <label class="block text-sm text-steel-700">
                            {{ __('staff.reason_optional') }}
                            <input type="text" name="reason" class="mt-1 block w-full max-w-md rounded border-2 border-steel-700 px-2 py-1">
                        </label>
                        <button type="submit" class="player-btn player-btn-primary">
                            {{ __('staff.grant_retries', ['student' => $detail['student_name'], 'block' => $block['block_type']]) }}
                        </button>
                    </form>
                @endif
            @endcan
        </section>
    @endforeach

    <section class="mb-8 rounded-lg border-2 border-steel-400 bg-white p-4" aria-labelledby="retry-grants">
        <h2 id="retry-grants" class="player-heading text-xl">{{ __('staff.retry_grants') }}</h2>
        @if (count($detail['retry_grants']) === 0)
            <p class="mt-2 text-sm text-steel-700">{{ __('staff.retry_grants_empty') }}</p>
        @else
            <ul class="mt-3 space-y-2">
                @foreach ($detail['retry_grants'] as $grant)
                    <li class="player-meta rounded border border-steel-300 p-3 text-sm text-steel-950">
                        {{ $grant['block_id'] }} — +{{ $grant['additional_attempts'] }}
                        — {{ __('staff.grant_by', ['name' => $grant['granted_by'] ?? '—']) }}
                        — {{ \App\Support\DisplayTime::toDayDateTimeString($grant['created_at'] ?? null) }}
                        @if ($grant['reason'])
                            — {{ $grant['reason'] }}
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif
    </section>

    @if (count($detail['reopens']) > 0)
        <section class="mb-8 rounded-lg border-2 border-steel-400 bg-white p-4" aria-labelledby="reopens">
            <h2 id="reopens" class="player-heading text-xl">{{ __('staff.reopens') }}</h2>
            <ul class="mt-3 space-y-2">
                @foreach ($detail['reopens'] as $reopen)
                    <li class="player-meta rounded border border-steel-300 p-3 text-sm text-steel-950">
                        {{ __('staff.reopened_by', ['name' => $reopen['reopened_by'] ?? '—']) }}
                        — {{ \App\Support\DisplayTime::toDayDateTimeString($reopen['created_at'] ?? null) }}
                        — {{ __('staff.previous_completed_at', ['when' => \App\Support\DisplayTime::toDayDateTimeString($reopen['previous_completed_at'] ?? null)]) }}
                        @if ($reopen['reason'])
                            — {{ $reopen['reason'] }}
                        @endif
                    </li>
                @endforeach
            </ul>
        </section>
    @endif
@endsection
