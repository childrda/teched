@extends('layouts.staff')

@section('title', __('staff.classes_title'))
@section('heading', __('staff.classes_title'))
@section('intro', __('staff.classes_intro'))

@section('nav')
    <a href="{{ route('staff.blocked-attempts') }}" class="underline">{{ __('staff.blocked_title') }}</a>
@endsection

@section('content')
    @if ($classes->isEmpty())
        <p class="text-slate-600">{{ __('staff.no_classes') }}</p>
    @else
        <table class="w-full border-collapse border-2 border-slate-400 bg-white text-left">
            <caption class="mb-2 text-left font-semibold">{{ __('staff.classes_title') }}</caption>
            <thead>
                <tr class="border-b-2 border-slate-400 bg-slate-100">
                    <th scope="col" class="px-3 py-2">Class</th>
                    <th scope="col" class="px-3 py-2">School year</th>
                    <th scope="col" class="px-3 py-2">{{ __('staff.assignments_title') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($classes as $class)
                    <tr class="border-b border-slate-300">
                        <th scope="row" class="px-3 py-3 font-semibold">
                            <a class="underline" href="{{ route('staff.classes.assignments', $class) }}">
                                {{ $class->name }}
                            </a>
                        </th>
                        <td class="px-3 py-3">{{ $class->school_year }}</td>
                        <td class="px-3 py-3">{{ $class->assignments_count }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        <div class="mt-4">{{ $classes->links() }}</div>
    @endif
@endsection
