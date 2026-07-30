@extends('layouts.staff')

@section('title', __('staff.confirm_reopen_title'))
@section('heading', __('staff.confirm_reopen_title'))

@section('nav')
    <a href="{{ route('staff.attempts.show', $attempt) }}" class="underline">{{ __('staff.cancel') }}</a>
@endsection

@section('content')
    <p class="mb-4">{{ __('staff.confirm_reopen_body') }}</p>
    <p class="mb-6 font-semibold">{{ $attempt->user->name }} — {{ $attempt->lesson->title }}</p>

    <form method="post" action="{{ route('staff.attempts.reopen', $attempt) }}" class="space-y-3">
        @csrf
        <label class="block text-sm">
            {{ __('staff.reason_optional') }}
            <input type="text" name="reason" class="mt-1 block w-full max-w-md border-2 border-slate-400 px-2 py-1">
        </label>
        <button type="submit" class="player-btn player-btn-primary">
            {{ __('staff.confirm_action') }}: {{ __('staff.reopen_attempt', ['student' => $attempt->user->name]) }}
        </button>
    </form>
@endsection
