@extends('layouts.staff')

@section('title', __('staff.assignments_title'))
@section('heading', __('staff.assignments_title'))
@section('intro', __('staff.assignments_intro', ['class' => $schoolClass->name]))

@section('nav')
    <a href="{{ route('staff.classes.index') }}" class="underline">{{ __('staff.back_classes') }}</a>
@endsection

@section('content')
    @if ($assignments->isEmpty())
        <p class="text-slate-600">{{ __('staff.no_assignments') }}</p>
    @else
        <table class="w-full border-collapse border-2 border-slate-400 bg-white text-left">
            <caption class="mb-2 text-left font-semibold">{{ $schoolClass->name }}</caption>
            <thead>
                <tr class="border-b-2 border-slate-400 bg-slate-100">
                    <th scope="col" class="px-3 py-2">{{ __('staff.column_lesson') }}</th>
                    <th scope="col" class="px-3 py-2">{{ __('staff.column_due') }}</th>
                    <th scope="col" class="px-3 py-2">Version</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($assignments as $assignment)
                    <tr class="border-b border-slate-300">
                        <th scope="row" class="px-3 py-3 font-semibold">
                            <a class="underline" href="{{ route('staff.assignments.show', $assignment) }}">
                                {{ $assignment->lesson->title }}
                            </a>
                            <span class="block text-sm font-normal text-slate-600">{{ $assignment->lesson->code }}</span>
                        </th>
                        <td class="px-3 py-3">
                            {{ $assignment->due_at ? __('staff.due_at', ['when' => \App\Support\DisplayTime::toDayDateTimeString($assignment->due_at)]) : '—' }}
                        </td>
                        <td class="px-3 py-3">{{ $assignment->lessonVersion?->version }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        <div class="mt-4">{{ $assignments->links() }}</div>
    @endif
@endsection
