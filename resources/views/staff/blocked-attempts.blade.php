<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Blocked attempts — {{ config('app.name', 'TechEd') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="bg-slate-50 text-slate-900">
    <div class="mx-auto max-w-4xl px-4 py-8">
        <header class="mb-8 flex flex-wrap items-center justify-between gap-4">
            <div>
                <h1 class="text-2xl font-semibold">Blocked attempts</h1>
                <p class="mt-1 text-slate-600">Students out of retries on a gradable block.</p>
            </div>
            <div class="flex items-center gap-4">
                <a href="{{ route('home') }}" class="underline">Home</a>
                <x-auth.session />
            </div>
        </header>

        @if (session('status'))
            <p class="mb-4 border border-slate-400 bg-white px-4 py-3" role="status">{{ session('status') }}</p>
        @endif

        @if (count($rows) === 0)
            <p class="text-slate-600">No blocked attempts right now.</p>
        @else
            <ul class="space-y-4">
                @foreach ($rows as $row)
                    @php $attempt = $row['attempt']; @endphp
                    <li class="border border-slate-300 bg-white p-4">
                        <p class="font-semibold">{{ $attempt->user->name }}</p>
                        <p class="text-sm text-slate-700">
                            {{ $attempt->lesson->title }} ({{ $attempt->lesson->code }})
                            — {{ $row['block_type'] }}
                            — used {{ $row['used'] }} / {{ $row['allowed'] }}
                        </p>

                        <div class="mt-4 flex flex-wrap gap-6">
                            <form method="post" action="{{ route('staff.attempts.grant-retries', $attempt) }}" class="space-y-2">
                                @csrf
                                <input type="hidden" name="block_id" value="{{ $row['block_id'] }}">
                                <label class="block text-sm">
                                    Additional attempts
                                    <input type="number" name="additional_attempts" value="1" min="1" max="20"
                                           class="mt-1 block w-24 border-2 border-slate-400 px-2 py-1">
                                </label>
                                <label class="block text-sm">
                                    Reason (optional)
                                    <input type="text" name="reason" class="mt-1 block w-full max-w-xs border-2 border-slate-400 px-2 py-1">
                                </label>
                                <button type="submit" class="player-btn player-btn-primary">Grant retries</button>
                            </form>

                            <form method="post" action="{{ route('staff.attempts.restart', $attempt) }}"
                                  onsubmit="return confirm('Restart this lesson? The current attempt will be superseded and kept for history.');">
                                @csrf
                                <button type="submit" class="player-btn player-btn-secondary">Restart lesson</button>
                            </form>
                        </div>
                    </li>
                @endforeach
            </ul>
        @endif
    </div>
</body>
</html>
