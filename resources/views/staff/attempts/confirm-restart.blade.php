@extends('layouts.staff')

@section('title', __('staff.confirm_restart_title'))
@section('heading', __('staff.confirm_restart_title'))

@section('nav')
    <a href="{{ route('staff.attempts.show', $attempt) }}" class="underline">{{ __('staff.cancel') }}</a>
@endsection

@section('content')
    <p class="mb-4">{{ __('staff.confirm_restart_body') }}</p>
    <p class="mb-6 font-semibold">{{ $attempt->user->name }} — {{ $attempt->lesson->title }}</p>

    <form method="post" action="{{ route('staff.attempts.restart', $attempt) }}">
        @csrf
        <button type="submit" class="player-btn player-btn-primary">
            {{ __('staff.confirm_action') }}: {{ __('staff.restart_lesson', ['student' => $attempt->user->name]) }}
        </button>
    </form>
@endsection
