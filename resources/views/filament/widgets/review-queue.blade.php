<x-filament-widgets::widget>
    <x-filament::section>
        <x-slot name="heading">
            Review queue
            @if (($queue['total'] ?? 0) > 0)
                <span class="text-sm font-normal text-gray-500">({{ $queue['total'] }} awaiting)</span>
            @endif
        </x-slot>
        <x-slot name="headerEnd">
            <a href="{{ $viewAllUrl }}" class="text-sm font-medium text-primary-600 underline">View all</a>
        </x-slot>

        @if (($queue['by_type'] ?? []) !== [])
            <p class="mb-3 text-sm text-gray-600">
                @foreach ($queue['by_type'] as $type)
                    <span class="mr-3">{{ $type['block_type'] }}: {{ $type['count'] }}</span>
                @endforeach
            </p>
        @endif

        @if (($queue['items'] ?? []) === [])
            <p class="text-sm text-gray-500">Nothing awaiting review.</p>
        @else
            <ul class="divide-y divide-gray-200 text-sm">
                @foreach ($queue['items'] as $item)
                    <li class="py-2">
                        <a href="{{ $item['url'] }}" class="font-medium text-primary-600 underline">
                            {{ $item['student_name'] }}
                        </a>
                        — {{ $item['block_type'] }}
                        <span class="text-gray-500">
                            · {{ $item['lesson_title'] }} · {{ $item['class_name'] }}
                            · {{ $item['submitted_at_label'] }}
                        </span>
                    </li>
                @endforeach
            </ul>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
