<x-filament-widgets::widget>
    <x-filament::section heading="Assignment progress">
        @if (($rows ?? []) === [])
            <p class="text-sm text-gray-500">No active assignments.</p>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead>
                        <tr class="border-b border-gray-200 text-gray-600">
                            <th class="py-2 pr-3 font-semibold">Lesson</th>
                            <th class="py-2 pr-3 font-semibold">Class</th>
                            <th class="py-2 pr-3 font-semibold">Not started</th>
                            <th class="py-2 pr-3 font-semibold">In progress</th>
                            <th class="py-2 pr-3 font-semibold">Completed</th>
                            <th class="py-2 pr-3 font-semibold">Mastered</th>
                            <th class="py-2 font-semibold">Avg completion</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($rows as $row)
                            <tr class="border-b border-gray-100">
                                <td class="py-2 pr-3">
                                    <a href="{{ $row['url'] }}" class="font-medium text-primary-600 underline">
                                        {{ $row['lesson_code'] }}
                                    </a>
                                    <span class="block text-gray-500">{{ $row['lesson_title'] }}</span>
                                </td>
                                <td class="py-2 pr-3">{{ $row['class_name'] }}</td>
                                <td class="py-2 pr-3">{{ $row['not_started'] }}</td>
                                <td class="py-2 pr-3">{{ $row['in_progress'] }}</td>
                                <td class="py-2 pr-3">{{ $row['completed'] }}</td>
                                <td class="py-2 pr-3">{{ $row['mastered'] }}</td>
                                <td class="py-2">{{ $row['avg_completion'] }}%</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
