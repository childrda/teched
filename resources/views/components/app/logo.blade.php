@props([
    'size' => 'md',
    'link' => false,
])

@php
    $heights = [
        'sm' => 'h-9',
        'md' => 'h-12',
        'lg' => 'h-16',
    ];
    $height = $heights[$size] ?? $heights['md'];
@endphp

@if ($link)
    <a href="{{ route('home') }}" {{ $attributes->class(['inline-flex shrink-0']) }}>
        <img
            src="{{ asset('images/logo.png') }}"
            alt="{{ config('app.name', 'Tech Learning System') }}"
            class="{{ $height }} w-auto"
        >
    </a>
@else
    <img
        src="{{ asset('images/logo.png') }}"
        alt="{{ config('app.name', 'Tech Learning System') }}"
        {{ $attributes->class([$height, 'w-auto', 'shrink-0']) }}
    >
@endif
