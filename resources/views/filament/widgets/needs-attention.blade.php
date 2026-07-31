<x-filament-widgets::widget>
    <x-filament::section>
        <x-slot name="heading">
            Needs attention
            @if (($total ?? 0) > 0)
                <span class="text-sm font-normal text-gray-500">({{ $total }})</span>
            @endif
        </x-slot>
        <x-slot name="headerEnd">
            <a href="{{ $viewAllUrl }}" class="text-sm font-medium text-primary-600 underline">View blocked</a>
        </x-slot>

        @if (($items ?? []) === [])
            <p class="text-sm text-gray-500">No blocked or inactive students.</p>
        @else
            <ul class="divide-y divide-gray-200 text-sm">
                @foreach ($items as $item)
                    <li class="py-2">
                        <a href="{{ $item['url'] }}" class="font-medium text-primary-600 underline">
                            {{ $item['student_name'] }}
                        </a>
                        <span @class(['font-semibold' => $item['reason'] === 'blocked'])>
                            — {{ $item['reason_label'] }}
                        </span>
                        <span class="text-gray-500">
                            · {{ $item['lesson_title'] }} · {{ $item['class_name'] }}
                            · {{ $item['last_activity_label'] }}
                        </span>
                    </li>
                @endforeach
            </ul>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
