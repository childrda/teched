@extends('layouts.staff')

@section('title', __('staff.assignment_title'))
@section('heading', $assignment->lesson->title)
@section('intro', $assignment->schoolClass->name.' — '.$assignment->lesson->code)

@section('nav')
    <a href="{{ route('staff.classes.assignments', $assignment->schoolClass) }}" class="underline">{{ __('staff.back_assignments') }}</a>
@endsection

@section('content')
    <p class="mb-4 text-sm text-slate-700">
        {{ __('staff.active_total', ['count' => $active_total]) }}
        · {{ __('staff.blocked_count', ['count' => $blocked_count]) }}
        · {{ __('staff.needs_review_count', ['count' => $needs_review_count]) }}
    </p>

    <table class="w-full border-collapse border-2 border-slate-400 bg-white text-left">
        <caption class="mb-2 text-left font-semibold">{{ __('staff.active_roster') }}</caption>
        <thead>
            <tr class="border-b-2 border-slate-400 bg-slate-100">
                <th scope="col" class="px-3 py-2">{{ __('staff.column_student') }}</th>
                <th scope="col" class="px-3 py-2">{{ __('staff.column_status') }}</th>
                <th scope="col" class="px-3 py-2">{{ __('staff.column_attempts') }}</th>
                <th scope="col" class="px-3 py-2">{{ __('staff.column_page') }}</th>
                <th scope="col" class="px-3 py-2">{{ __('staff.column_started') }}</th>
                <th scope="col" class="px-3 py-2">{{ __('staff.column_completed') }}</th>
                <th scope="col" class="px-3 py-2">{{ __('staff.column_active_time') }}</th>
                <th scope="col" class="px-3 py-2">{{ __('staff.column_flags') }}</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($active as $row)
                <tr class="border-b border-slate-300">
                    <th scope="row" class="px-3 py-3 font-semibold">
                        @if ($row['primary_attempt_id'])
                            <a class="underline" href="{{ route('staff.attempts.show', $row['primary_attempt_id']) }}">
                                {{ $row['name'] }}
                            </a>
                        @else
                            {{ $row['name'] }}
                        @endif
                    </th>
                    <td class="px-3 py-3">{{ $row['status_label'] }}</td>
                    <td class="px-3 py-3">{{ __('staff.attempt_count', ['count' => $row['attempt_count']]) }}</td>
                    <td class="px-3 py-3">{{ $row['current_page'] ?? '—' }}</td>
                    <td class="px-3 py-3">{{ \App\Support\DisplayTime::toDayDateTimeString($row['started_at'] ?? null) }}</td>
                    <td class="px-3 py-3">{{ \App\Support\DisplayTime::toDayDateTimeString($row['completed_at'] ?? null) }}</td>
                    <td class="px-3 py-3">
                        <span class="sr-only">{{ __('staff.active_time') }}: </span>
                        {{ gmdate('H:i:s', (int) $row['active_seconds']) }}
                    </td>
                    <td class="px-3 py-3">
                        @if ($row['blocked'])
                            <span>{{ __('staff.blocked') }}</span>
                        @endif
                        @if ($row['needs_review'])
                            <span>@if ($row['blocked']) · @endif{{ __('staff.needs_review') }}</span>
                        @endif
                        @if (! $row['blocked'] && ! $row['needs_review'])
                            —
                        @endif
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="8" class="px-3 py-4 text-slate-600">{{ __('staff.no_assignments') }}</td>
                </tr>
            @endforelse
        </tbody>
    </table>

    <div class="mt-4">{{ $active->links() }}</div>

    @if (count($withdrawn) > 0)
        <table class="mt-10 w-full border-collapse border-2 border-slate-400 bg-white text-left">
            <caption class="mb-2 text-left font-semibold">{{ __('staff.withdrawn_roster') }}</caption>
            <thead>
                <tr class="border-b-2 border-slate-400 bg-slate-100">
                    <th scope="col" class="px-3 py-2">{{ __('staff.column_student') }}</th>
                    <th scope="col" class="px-3 py-2">{{ __('staff.column_status') }}</th>
                    <th scope="col" class="px-3 py-2">{{ __('staff.column_attempts') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($withdrawn as $row)
                    <tr class="border-b border-slate-300">
                        <th scope="row" class="px-3 py-3 font-semibold">
                            @if ($row['primary_attempt_id'])
                                <a class="underline" href="{{ route('staff.attempts.show', $row['primary_attempt_id']) }}">
                                    {{ $row['name'] }}
                                </a>
                            @else
                                {{ $row['name'] }}
                            @endif
                        </th>
                        <td class="px-3 py-3">{{ $row['status_label'] }}</td>
                        <td class="px-3 py-3">{{ __('staff.attempt_count', ['count' => $row['attempt_count']]) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
@endsection
