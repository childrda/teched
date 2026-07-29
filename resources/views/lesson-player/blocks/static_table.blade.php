@php
    $headers = array_values($config['headers'] ?? []);
    $rows = array_values($config['rows'] ?? []);
    $caption = $config['caption'] ?? null;
    $firstColumnIsHeader = (bool) ($config['first_column_is_header'] ?? false);
@endphp

<div class="player-card">
    {{-- A real table wherever there is room for one. --}}
    <div class="hidden overflow-x-auto sm:block">
        <table class="w-full border-collapse text-left">
            @if (filled($caption))
                <caption class="mb-2 text-left text-sm font-semibold text-slate-700" data-speech-id="caption">
                    {{ $caption }}
                </caption>
            @endif

            <thead>
                <tr>
                    @foreach ($headers as $header)
                        <th scope="col" class="border-2 border-slate-400 bg-slate-100 px-3 py-2 align-top font-semibold">
                            {{ $header }}
                        </th>
                    @endforeach
                </tr>
            </thead>

            <tbody>
                @foreach ($rows as $rowIndex => $row)
                    <tr data-speech-id="row:{{ $rowIndex }}">
                        @foreach (array_values($row) as $cellIndex => $cell)
                            @if ($firstColumnIsHeader && $cellIndex === 0)
                                <th scope="row" class="border-2 border-slate-400 px-3 py-2 align-top font-semibold">
                                    {{ $cell }}
                                </th>
                            @else
                                <td class="border-2 border-slate-400 px-3 py-2 align-top">{{ $cell }}</td>
                            @endif
                        @endforeach
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    {{-- Narrow screens: one card per row, repeating the column header beside
         every cell so no value is left without its label. --}}
    <div class="sm:hidden">
        @if (filled($caption))
            <p class="mb-2 text-sm font-semibold text-slate-700" data-speech-id="caption">{{ $caption }}</p>
        @endif

        <div class="space-y-4">
            @foreach ($rows as $rowIndex => $row)
                @php $cells = array_values($row); @endphp

                <div class="rounded border-2 border-slate-400" data-speech-id="row:{{ $rowIndex }}">
                    @if ($firstColumnIsHeader && array_key_exists(0, $cells))
                        <p class="border-b-2 border-slate-400 bg-slate-100 px-3 py-2 font-bold">{{ $cells[0] }}</p>
                    @endif

                    <dl class="divide-y-2 divide-slate-400">
                        @foreach ($cells as $cellIndex => $cell)
                            @continue($firstColumnIsHeader && $cellIndex === 0)

                            <div class="flex flex-wrap gap-x-2 px-3 py-2">
                                <dt class="font-semibold">{{ $headers[$cellIndex] ?? '' }}</dt>
                                <dd>{{ $cell }}</dd>
                            </div>
                        @endforeach
                    </dl>
                </div>
            @endforeach
        </div>
    </div>
</div>
