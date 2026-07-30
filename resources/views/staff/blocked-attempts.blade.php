@extends('layouts.staff')

@section('title', __('staff.blocked_title'))
@section('heading', __('staff.blocked_title'))
@section('intro', __('staff.blocked_intro'))

@section('nav')
    <a href="{{ route('staff.classes.index') }}" class="underline">{{ __('staff.classes_title') }}</a>
@endsection

@section('content')
    @if (count($rows) === 0)
        <p class="text-slate-600">{{ __('staff.no_blocked') }}</p>
    @else
        <table class="w-full border-collapse border-2 border-slate-400 bg-white text-left">
            <caption class="mb-2 text-left font-semibold">{{ __('staff.blocked_title') }}</caption>
            <thead>
                <tr class="border-b-2 border-slate-400 bg-slate-100">
                    <th scope="col" class="px-3 py-2">{{ __('staff.column_student') }}</th>
                    <th scope="col" class="px-3 py-2">{{ __('staff.column_lesson') }}</th>
                    <th scope="col" class="px-3 py-2">Block</th>
                    <th scope="col" class="px-3 py-2">{{ __('staff.column_attempts') }}</th>
                    <th scope="col" class="px-3 py-2">{{ __('staff.view_attempt') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($rows as $row)
                    @php $attempt = $row['attempt']; @endphp
                    <tr class="border-b border-slate-300">
                        <th scope="row" class="px-3 py-3 font-semibold">{{ $attempt->user->name }}</th>
                        <td class="px-3 py-3">{{ $attempt->lesson->title }} ({{ $attempt->lesson->code }})</td>
                        <td class="px-3 py-3">{{ $row['block_type'] }}</td>
                        <td class="px-3 py-3">{{ $row['used'] }} / {{ $row['allowed'] }}</td>
                        <td class="px-3 py-3">
                            <a class="underline" href="{{ route('staff.attempts.show', $attempt) }}">
                                {{ __('staff.view_attempt') }}
                            </a>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
@endsection
