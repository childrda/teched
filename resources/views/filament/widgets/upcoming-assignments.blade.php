<x-filament-widgets::widget>
    <x-filament::section heading="Upcoming due dates">
        @if (($items ?? []) === [])
            <p class="text-sm text-gray-500">No upcoming due dates.</p>
        @else
            <ul class="divide-y divide-gray-200 text-sm">
                @foreach ($items as $item)
                    <li class="py-2">
                        <a href="{{ $item['url'] }}" class="font-medium text-primary-600 underline">
                            {{ $item['lesson_code'] }} — {{ $item['lesson_title'] }}
                        </a>
                        <span class="text-gray-500">
                            · {{ $item['class_name'] }}
                            · Due {{ $item['due_at_label'] }}
                            · {{ $item['not_started'] }} of {{ $item['student_total'] }} not started
                        </span>
                    </li>
                @endforeach
            </ul>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
