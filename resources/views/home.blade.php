<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ config('app.name', 'TechEd') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="bg-slate-50 text-slate-900">
    <div class="mx-auto max-w-3xl px-4 py-8">
        <header class="mb-8 flex flex-wrap items-center justify-between gap-4">
            <div>
                <h1 class="text-2xl font-semibold">{{ config('app.name', 'TechEd') }}</h1>
                <p class="mt-1 text-slate-600">Published lessons</p>
            </div>
            <x-auth.session />
        </header>

        @if ($isStaff)
            <p class="mb-6">
                <a href="{{ route('staff.blocked-attempts') }}" class="font-medium text-slate-900 underline">
                    Blocked attempts
                </a>
            </p>
        @endif

        @if ($rows->isEmpty())
            <p class="text-slate-600">No published lessons yet.</p>
        @else
            <ul class="space-y-3">
                @foreach ($rows as $row)
                    @php
                        $lesson = $row['lesson'];
                        $attempt = $row['attempt'];
                    @endphp
                    <li class="flex flex-wrap items-center justify-between gap-3 border border-slate-300 bg-white px-4 py-3">
                        <div>
                            <p class="font-semibold">{{ $lesson->title }}</p>
                            <p class="text-sm text-slate-600">
                                {{ $lesson->code }}
                                @if ($attempt)
                                    — {{ str_replace('_', ' ', $attempt->status->value) }}
                                    @if ($row['read_only'])
                                        (read-only)
                                    @endif
                                @else
                                    — not started
                                @endif
                            </p>
                        </div>
                        <a href="{{ route('lessons.play', $lesson->code) }}"
                           class="inline-flex min-h-11 items-center rounded-md border-2 border-slate-800 px-4 py-2 text-sm font-semibold">
                            {{ $attempt ? ($row['read_only'] ? 'Review' : 'Resume') : 'Start' }}
                        </a>
                    </li>
                @endforeach
            </ul>
        @endif
    </div>
</body>
</html>
