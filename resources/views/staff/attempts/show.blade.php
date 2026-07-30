@php
    /** @var array<string, mixed> $detail */
    $attempt = $detail['attempt'];
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
    <section class="mb-8 border-2 border-slate-400 bg-white p-4" aria-labelledby="attempt-context">
        <h2 id="attempt-context" class="text-lg font-semibold">{{ __('staff.attempt_title') }}</h2>
        <dl class="mt-3 grid gap-2 sm:grid-cols-2">
            <div>
                <dt class="text-sm font-semibold">{{ __('staff.column_status') }}</dt>
                <dd>{{ $detail['status_label'] }}
                    @if ($detail['blocked'])
                        — {{ __('staff.blocked') }}
                    @endif
                </dd>
            </div>
            <div>
                <dt class="text-sm font-semibold">{{ __('staff.pinned_version', ['number' => $detail['version_number']]) }}</dt>
                <dd>{{ $detail['class_name'] ?? '—' }}</dd>
            </div>
            <div>
                <dt class="text-sm font-semibold">{{ __('staff.current_page') }}</dt>
                <dd>{{ $detail['current_page_position'] ?? '—' }}
                    @if ($detail['current_page_title'])
                        — {{ $detail['current_page_title'] }}
                    @endif
                </dd>
            </div>
            <div>
                <dt class="text-sm font-semibold">{{ __('staff.active_time') }}</dt>
                <dd>{{ gmdate('H:i:s', (int) $detail['active_seconds']) }}</dd>
            </div>
            <div>
                <dt class="text-sm font-semibold">{{ __('staff.started_at') }}</dt>
                <dd>{{ $detail['started_at']?->toDayDateTimeString() ?? '—' }}</dd>
            </div>
            <div>
                <dt class="text-sm font-semibold">{{ __('staff.completed_at') }}</dt>
                <dd>{{ $detail['completed_at']?->toDayDateTimeString() ?? '—' }}</dd>
            </div>
            @if ($detail['superseded_at'])
                <div>
                    <dt class="text-sm font-semibold">{{ __('staff.superseded_at') }}</dt>
                    <dd>{{ $detail['superseded_at']->toDayDateTimeString() }}
                        @if ($detail['superseded_by'])
                            ({{ $detail['superseded_by'] }})
                        @endif
                    </dd>
                </div>
            @endif
            @if ($detail['time_to_complete_seconds'] !== null)
                <div>
                    <dt class="text-sm font-semibold">{{ __('staff.time_to_complete') }}</dt>
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
        <section class="mb-8 border-2 border-slate-400 bg-white p-4"
                 aria-labelledby="block-{{ $block['block_id'] }}">
            <h2 id="block-{{ $block['block_id'] }}" class="text-lg font-semibold">
                {{ $block['block_type'] }}
                <span class="text-sm font-normal text-slate-600">({{ $block['block_id'] }})</span>
                @if ($block['needs_review'])
                    — {{ __('staff.needs_review') }}
                @endif
            </h2>
            @if ($block['page_title'])
                <p class="text-sm text-slate-600">{{ $block['page_title'] }}</p>
            @endif

            @if ($block['attempts'])
                <p class="mt-2 text-sm">
                    @if ($block['attempts']['allowed'] === null)
                        {{ __('staff.attempts_unlimited', ['used' => $block['attempts']['used']]) }}
                    @else
                        {{ __('staff.attempts_used', ['used' => $block['attempts']['used'], 'allowed' => $block['attempts']['allowed']]) }}
                    @endif
                </p>
            @endif

            <div class="mt-4 border-t-2 border-slate-300 pt-4">
                <h3 class="font-semibold">{{ __('staff.current_work') }}</h3>
                @if (! $block['current_work']['has_state'])
                    <p class="mt-2 text-sm text-slate-600">{{ __('staff.current_work_empty') }}</p>
                @else
                    <p class="mt-2 text-sm">
                        {{ __('staff.current_work_updated', ['when' => $block['current_work']['updated_at']?->toDayDateTimeString() ?? '—']) }}
                    </p>
                    @if ($block['current_work']['differs_from_latest_submission'])
                        <p class="mt-1 text-sm font-semibold">{{ __('staff.draft_differs') }}</p>
                    @elseif (count($block['submitted_history']) > 0)
                        <p class="mt-1 text-sm">{{ __('staff.draft_matches') }}</p>
                    @endif
                    <pre class="mt-2 overflow-x-auto rounded border border-slate-300 bg-slate-50 p-3 text-sm">{{ json_encode($block['current_work']['state'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
                @endif
            </div>

            <div class="mt-4 border-t-2 border-slate-300 pt-4">
                <h3 class="font-semibold">{{ __('staff.submitted_history') }}</h3>
                @if (count($block['submitted_history']) === 0)
                    <p class="mt-2 text-sm text-slate-600">{{ __('staff.submitted_history_empty') }}</p>
                @else
                    <ul class="mt-3 space-y-4">
                        @foreach ($block['submitted_history'] as $submission)
                            <li class="rounded border border-slate-300 p-3">
                                <p class="font-semibold">
                                    {{ __('staff.submission_number', ['number' => $submission['attempt_number']]) }}
                                    — {{ __('staff.submitted_at', ['when' => $submission['submitted_at']?->toDayDateTimeString() ?? '—']) }}
                                    @if ($submission['requires_manual_review'])
                                        — {{ __('staff.needs_review') }}
                                    @endif
                                </p>
                                @if ($submission['score'] !== null)
                                    <p class="text-sm">
                                        {{ __('staff.score', [
                                            'score' => $submission['score'],
                                            'max' => $submission['max_score'],
                                            'percent' => $submission['max_score'] ? round(($submission['score'] / $submission['max_score']) * 100) : 0,
                                        ]) }}
                                        — {{ $submission['passed'] ? __('staff.passed') : __('staff.not_passed') }}
                                    </p>
                                @endif
                                <pre class="mt-2 overflow-x-auto rounded border border-slate-200 bg-slate-50 p-2 text-sm">{{ json_encode($submission['response'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>

            @if (count($block['all_results']) > 0)
                <div class="mt-4 border-t-2 border-slate-300 pt-4">
                    <h3 class="font-semibold">{{ __('staff.grading') }}</h3>
                    @if ($block['emphasize_first'] && $block['first_result'])
                        <p class="mt-2 text-sm font-semibold">{{ __('staff.first_result') }}</p>
                        @include('staff.attempts.partials.teacher-result', ['result' => $block['first_result']])
                    @endif
                    @if ($block['latest_result'])
                        <p class="mt-4 text-sm font-semibold">{{ __('staff.latest_result') }}</p>
                        @include('staff.attempts.partials.teacher-result', ['result' => $block['latest_result']])
                    @endif
                </div>
            @endif

            @can('intervene', $attempt)
                @if ($block['auto_gradable'])
                    <form method="post" action="{{ route('staff.attempts.grant-retries', $attempt) }}" class="mt-4 space-y-2 border-t-2 border-slate-300 pt-4">
                        @csrf
                        <input type="hidden" name="block_id" value="{{ $block['block_id'] }}">
                        <label class="block text-sm font-semibold">
                            {{ __('staff.additional_attempts') }}
                            <input type="number" name="additional_attempts" value="1" min="1" max="20"
                                   class="mt-1 block w-24 border-2 border-slate-400 px-2 py-1">
                        </label>
                        <label class="block text-sm">
                            {{ __('staff.reason_optional') }}
                            <input type="text" name="reason" class="mt-1 block w-full max-w-md border-2 border-slate-400 px-2 py-1">
                        </label>
                        <button type="submit" class="player-btn player-btn-primary">
                            {{ __('staff.grant_retries', ['student' => $detail['student_name'], 'block' => $block['block_type']]) }}
                        </button>
                    </form>
                @endif
            @endcan
        </section>
    @endforeach

    <section class="mb-8 border-2 border-slate-400 bg-white p-4" aria-labelledby="retry-grants">
        <h2 id="retry-grants" class="text-lg font-semibold">{{ __('staff.retry_grants') }}</h2>
        @if (count($detail['retry_grants']) === 0)
            <p class="mt-2 text-sm text-slate-600">{{ __('staff.retry_grants_empty') }}</p>
        @else
            <ul class="mt-3 space-y-2">
                @foreach ($detail['retry_grants'] as $grant)
                    <li class="border border-slate-300 p-3 text-sm">
                        {{ $grant['block_id'] }} — +{{ $grant['additional_attempts'] }}
                        — {{ __('staff.grant_by', ['name' => $grant['granted_by'] ?? '—']) }}
                        — {{ $grant['created_at']?->toDayDateTimeString() }}
                        @if ($grant['reason'])
                            — {{ $grant['reason'] }}
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif
    </section>

    @if (count($detail['reopens']) > 0)
        <section class="mb-8 border-2 border-slate-400 bg-white p-4" aria-labelledby="reopens">
            <h2 id="reopens" class="text-lg font-semibold">{{ __('staff.reopens') }}</h2>
            <ul class="mt-3 space-y-2">
                @foreach ($detail['reopens'] as $reopen)
                    <li class="border border-slate-300 p-3 text-sm">
                        {{ __('staff.reopened_by', ['name' => $reopen['reopened_by'] ?? '—']) }}
                        — {{ $reopen['created_at']?->toDayDateTimeString() }}
                        — {{ __('staff.previous_completed_at', ['when' => $reopen['previous_completed_at']?->toDayDateTimeString() ?? '—']) }}
                        @if ($reopen['reason'])
                            — {{ $reopen['reason'] }}
                        @endif
                    </li>
                @endforeach
            </ul>
        </section>
    @endif
@endsection
