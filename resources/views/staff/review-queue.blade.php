@extends('layouts.staff')

@section('title', 'Review queue')
@section('heading', 'Review queue')
@section('intro', 'Unreviewed short responses and CER submissions across your classes.')

@section('nav')
    <a href="{{ route('staff.classes.index') }}" class="underline">{{ __('staff.classes_title') }}</a>
@endsection

@section('content')
    <p class="mb-4 text-sm text-slate-600">{{ $queue['total'] }} awaiting review</p>

    @if (($queue['items'] ?? []) === [])
        <p class="text-slate-600">Nothing awaiting review.</p>
    @else
        <ul class="space-y-3">
            @foreach ($queue['items'] as $item)
                <li class="border-2 border-slate-400 bg-white px-4 py-3">
                    <a class="font-semibold underline" href="{{ $item['url'] }}">{{ $item['student_name'] }}</a>
                    — {{ $item['block_type'] }}
                    <span class="block text-sm text-slate-600">
                        {{ $item['lesson_title'] }} · {{ $item['class_name'] }} · {{ $item['submitted_at_label'] }}
                    </span>
                </li>
            @endforeach
        </ul>
    @endif
@endsection
