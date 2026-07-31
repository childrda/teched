<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    @include('partials.favicon')
    <title>@yield('title') — {{ config('app.name', 'TechEd') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="bg-slate-50 text-slate-900">
    <div class="mx-auto max-w-5xl px-4 py-8">
        <header class="mb-8 space-y-4">
            <div class="flex flex-wrap items-center justify-between gap-4">
                <x-app.logo size="sm" :link="true" />
                <div class="flex flex-wrap items-center gap-4">
                    @yield('nav')
                    @auth
                        @if (auth()->user()->isTeacher() || auth()->user()->isAdmin())
                            <a href="{{ url('/admin') }}" class="underline">{{ __('staff.authoring_panel') }}</a>
                        @endif
                    @endauth
                    <a href="{{ route('home') }}" class="underline">{{ __('staff.back_home') }}</a>
                    <x-auth.session />
                </div>
            </div>
            <div>
                <h1 class="text-2xl font-semibold">@yield('heading')</h1>
                @hasSection('intro')
                    <p class="mt-1 text-slate-600">@yield('intro')</p>
                @endif
            </div>
        </header>

        @if (session('status'))
            <p class="mb-4 border border-slate-400 bg-white px-4 py-3" role="status">{{ session('status') }}</p>
        @endif

        @if ($errors->any())
            <div class="mb-4 border border-red-700 bg-red-50 px-4 py-3" role="alert">
                <ul class="list-disc pl-5">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        @yield('content')
    </div>
</body>
</html>
