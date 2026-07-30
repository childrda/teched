<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    @include('partials.favicon')
    <title>Sign in — {{ config('app.name', 'TechEd') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="flex min-h-full items-center justify-center bg-slate-100 px-4 py-8 font-sans text-slate-900">

<main class="w-full max-w-md">
    <div class="mb-6 flex justify-center">
        <x-app.logo size="lg" />
    </div>
    <div class="player-card space-y-6">
        <h1 class="text-2xl font-bold">Sign in</h1>

        @if (session('auth_notice'))
            {{-- Expired-form notice: same summary pattern as validation errors
                 (alert + autofocus) but visually distinct — not a failed credential. --}}
            <div id="login-notice"
                 class="rounded-md border-2 border-slate-500 bg-slate-50 p-3"
                 role="alert"
                 tabindex="-1"
                 autofocus>
                <p class="font-semibold text-slate-900">{{ session('auth_notice') }}</p>
            </div>
        @endif

        @if ($errors->any())
            {{-- autofocus moves the reader here after a failed attempt so the
                 generic message is announced rather than left unread. --}}
            <div id="login-errors"
                 class="rounded-md border-2 border-amber-800 bg-amber-50 p-3"
                 role="alert"
                 tabindex="-1"
                 @if (! session('auth_notice')) autofocus @endif>
                <p class="font-semibold text-amber-950">{{ $errors->first() }}</p>
            </div>
        @endif

        <form method="POST" action="{{ route('login') }}" class="space-y-4" novalidate>
            @csrf

            <div>
                <label class="player-field-label" for="email">Email</label>
                <input id="email"
                       name="email"
                       type="email"
                       value="{{ old('email') }}"
                       required
                       autocomplete="email"
                       @if ($errors->any()) aria-describedby="login-errors" aria-invalid="true"
                       @elseif (session('auth_notice')) aria-describedby="login-notice"
                       @endif
                       class="mt-2 min-h-11 w-full rounded-md border-2 border-slate-500 bg-white px-3 py-2 text-base">
            </div>

            <div>
                <label class="player-field-label" for="password">Password</label>
                <input id="password"
                       name="password"
                       type="password"
                       required
                       autocomplete="current-password"
                       @if ($errors->any()) aria-describedby="login-errors" aria-invalid="true"
                       @elseif (session('auth_notice')) aria-describedby="login-notice"
                       @endif
                       class="mt-2 min-h-11 w-full rounded-md border-2 border-slate-500 bg-white px-3 py-2 text-base">
            </div>

            <button type="submit" class="player-btn player-btn-primary w-full">Sign in</button>
        </form>
    </div>
</main>

</body>
</html>
