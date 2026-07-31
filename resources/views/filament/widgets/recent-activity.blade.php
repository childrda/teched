<x-filament-widgets::widget>
    <x-filament::section heading="Recent activity">
        @if (($items ?? []) === [])
            <p class="text-sm text-gray-500">No recent submissions or completions.</p>
        @else
            <ul class="divide-y divide-gray-200 text-sm">
                @foreach ($items as $item)
                    <li class="py-2">
                        @if ($item['url'])
                            <a href="{{ $item['url'] }}" class="font-medium text-primary-600 underline">
                                {{ $item['student_name'] }}
                            </a>
                        @else
                            <span class="font-medium">{{ $item['student_name'] }}</span>
                        @endif
                        — {{ $item['type_label'] }}
                        <span class="text-gray-500">
                            · {{ $item['lesson_title'] }} · {{ $item['class_name'] }}
                            · {{ $item['at_label'] }}
                        </span>
                    </li>
                @endforeach
            </ul>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
