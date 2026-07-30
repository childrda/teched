<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('player.assignment_unavailable_title') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="bg-slate-50 text-slate-900">
    <div class="mx-auto max-w-lg px-4 py-16">
        <h1 class="text-2xl font-semibold">{{ __('player.assignment_unavailable_title') }}</h1>
        <p class="mt-4 text-slate-700">{{ __('player.assignment_unavailable_body') }}</p>
        @if ($assignment->available_at)
            <p class="mt-2 text-sm text-slate-600">
                {{ __('player.assignment_available_at', ['when' => $assignment->available_at->timezone(config('app.timezone'))->toDayDateTimeString()]) }}
            </p>
        @endif
        <p class="mt-8"><a href="{{ route('home') }}" class="underline">{{ __('player.back_home') }}</a></p>
    </div>
</body>
</html>
