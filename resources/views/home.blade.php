<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    @include('partials.favicon')
    <title>{{ config('app.name', 'TechEd') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="bg-slate-50 text-slate-900">
    <div class="mx-auto max-w-3xl px-4 py-8">
        <header class="mb-8 flex flex-wrap items-center justify-between gap-4">
            <div>
                <h1 class="sr-only">{{ config('app.name', 'Tech Learning System') }}</h1>
                <x-app.logo size="lg" />
                <p class="mt-3 text-slate-600">
                    {{ $isStaff ? __('home.staff_intro') : __('home.student_intro') }}
                </p>
            </div>
            <x-auth.session />
        </header>

        @if ($isStaff)
            <p class="mb-6 flex flex-wrap gap-4">
                <a href="{{ url('/admin') }}" class="font-medium text-slate-900 underline">
                    {{ __('staff.authoring_panel') }}
                </a>
                <a href="{{ route('staff.classes.index') }}" class="font-medium text-slate-900 underline">
                    {{ __('home.view_classes') }}
                </a>
                <a href="{{ route('staff.blocked-attempts') }}" class="font-medium text-slate-900 underline">
                    {{ __('home.blocked_attempts') }}
                </a>
            </p>

            @if ($classes->isEmpty())
                <p class="text-slate-600">{{ __('home.no_classes') }}</p>
            @else
                <table class="w-full border-collapse border-2 border-slate-400 bg-white text-left">
                    <caption class="mb-2 text-left font-semibold">{{ __('home.staff_intro') }}</caption>
                    <thead>
                        <tr class="border-b-2 border-slate-400 bg-slate-100">
                            <th scope="col" class="px-3 py-2">Class</th>
                            <th scope="col" class="px-3 py-2">{{ __('staff.assignments_title') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($classes as $class)
                            <tr class="border-b border-slate-300">
                                <th scope="row" class="px-3 py-3">
                                    <a class="font-semibold underline" href="{{ route('staff.classes.assignments', $class) }}">
                                        {{ $class->name }}
                                    </a>
                                    <span class="block text-sm font-normal text-slate-600">{{ $class->school_year }}</span>
                                </th>
                                <td class="px-3 py-3">{{ $class->assignments_count }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
                <div class="mt-4">{{ $classes->links() }}</div>
            @endif
        @else
            @if (count($assignments) === 0 && $completed_assignment_count === 0)
                <p class="text-slate-600">{{ __('home.no_assignments') }}</p>
            @else
                <table class="w-full border-collapse border-2 border-slate-400 bg-white text-left">
                    <caption class="mb-2 text-left font-semibold">{{ __('home.student_intro') }}</caption>
                    <thead>
                        <tr class="border-b-2 border-slate-400 bg-slate-100">
                            <th scope="col" class="px-3 py-2">{{ __('staff.column_lesson') }}</th>
                            <th scope="col" class="px-3 py-2">{{ __('staff.column_status') }}</th>
                            <th scope="col" class="px-3 py-2">{{ __('staff.column_due') }}</th>
                            <th scope="col" class="px-3 py-2">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($assignments as $row)
                            <tr class="border-b border-slate-300">
                                <th scope="row" class="px-3 py-3">
                                    <span class="font-semibold">{{ $row['lesson_title'] }}</span>
                                    <span class="block text-sm font-normal text-slate-600">
                                        {{ $row['lesson_code'] }} — {{ __('home.class_label', ['name' => $row['class_name']]) }}
                                    </span>
                                    @if (! $row['active_membership'] && $row['withdrawn_reason'])
                                        <span class="mt-1 block text-sm font-normal">{{ $row['withdrawn_reason'] }}</span>
                                    @endif
                                    @if (! $row['available'] && $row['available_at'])
                                        <span class="mt-1 block text-sm font-normal">
                                            {{ __('home.available_at', ['when' => \App\Support\DisplayTime::toDayDateTimeString($row['available_at'])]) }}
                                        </span>
                                    @endif
                                </th>
                                <td class="px-3 py-3">{{ $row['status_label'] }}</td>
                                <td class="px-3 py-3">
                                    {{ $row['due_at'] ? __('home.due_at', ['when' => \App\Support\DisplayTime::toDayDateTimeString($row['due_at'])]) : '—' }}
                                </td>
                                <td class="px-3 py-3">
                                    @if ($row['url'] && $row['action_label'])
                                        <a href="{{ $row['url'] }}"
                                           class="inline-flex min-h-11 items-center rounded-md border-2 border-slate-800 px-4 py-2 text-sm font-semibold">
                                            {{ $row['action_label'] }}
                                        </a>
                                    @else
                                        —
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>

                @if ($completed_assignment_count > 0)
                    <form method="post" action="{{ route('preferences.student-dashboard') }}" class="mt-3">
                        @csrf
                        <input type="hidden" name="show_completed_assignments"
                               value="{{ $show_completed_assignments ? 0 : 1 }}">
                        <button type="submit" class="text-sm font-medium underline">
                            {{ $show_completed_assignments
                                ? __('home.hide_completed_assignments', ['count' => $completed_assignment_count])
                                : __('home.show_completed_assignments', ['count' => $completed_assignment_count]) }}
                        </button>
                    </form>
                @endif
            @endif

            <h2 class="mt-10 text-xl font-semibold">{{ __('home.practice_intro') }}</h2>
            @if (count($practice) === 0 && $completed_practice_count === 0)
                <p class="mt-3 text-slate-600">{{ __('home.no_practice') }}</p>
            @else
                <ul class="mt-3 space-y-3">
                    @foreach ($practice as $row)
                        <li class="flex flex-wrap items-center justify-between gap-3 border border-slate-300 bg-white px-4 py-3">
                            <div>
                                <p class="font-semibold">{{ $row['lesson']->title }}</p>
                                <p class="text-sm text-slate-600">{{ $row['lesson']->code }} — {{ $row['status_label'] }}</p>
                            </div>
                            <a href="{{ $row['url'] }}"
                               class="inline-flex min-h-11 items-center rounded-md border-2 border-slate-800 px-4 py-2 text-sm font-semibold">
                                {{ $row['action'] === 'resume' ? __('home.action_resume') : __('home.action_view') }}
                            </a>
                        </li>
                    @endforeach
                </ul>

                @if ($completed_practice_count > 0)
                    <form method="post" action="{{ route('preferences.student-dashboard') }}" class="mt-3">
                        @csrf
                        <input type="hidden" name="show_completed_practice"
                               value="{{ $show_completed_practice ? 0 : 1 }}">
                        <button type="submit" class="text-sm font-medium underline">
                            {{ $show_completed_practice
                                ? __('home.hide_completed_practice', ['count' => $completed_practice_count])
                                : __('home.show_completed_practice', ['count' => $completed_practice_count]) }}
                        </button>
                    </form>
                @endif
            @endif
        @endif
    </div>
</body>
</html>
