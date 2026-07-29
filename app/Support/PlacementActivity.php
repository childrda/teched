<?php

namespace App\Support;

/**
 * Turns a redacted placement block into the payload its Alpine controller is
 * initialised with.
 *
 * Both placement renderers hand the same controller the same shape, so the
 * differences between a matching row and an image hotspot are settled here,
 * once, in PHP: how a slot is named, which items carry a speech segment, and
 * how two items with the same label are told apart.
 *
 * Nothing an author marked as an answer reaches this payload. It is built
 * from a config that has already been through redactConfig(), and it copies
 * only the fields it names.
 */
final class PlacementActivity
{
    /**
     * Every string the controller may announce or build a name from. The
     * controller holds no English of its own, so this list is also what
     * tests/Feature/PlacementLocalizationTest.php checks against lang/en.
     */
    public const STRINGS = [
        'picked_up',
        'placed_at',
        'moved_to',
        'placed_over',
        'returned',
        'cancelled',
        'select_first',
        'complete',
        'reset',
        'gate',
        'slot_empty',
        'slot_filled',
    ];

    /**
     * @param array<string, mixed> $config a redacted matching config
     * @return array<string, mixed>
     */
    public static function forMatching(
        array $config,
        string $blockId,
        string $pageId,
        string $completionType
    ): array {
        return [
            ...self::shell($blockId, $pageId, $completionType, (bool) ($config['shuffle'] ?? false)),
            // Matching speaks every bank label, so each item names the
            // segment the player highlights while reading it.
            'items' => self::items($config['bank'] ?? [], fn (string $id) => "{$id}:label"),
            'slots' => self::slots($config['slots'] ?? [], 'placement.row'),
        ];
    }

    /**
     * @param array<string, mixed> $config a redacted image_labeling config
     * @return array<string, mixed>
     */
    public static function forImageLabeling(
        array $config,
        string $blockId,
        string $pageId,
        string $completionType
    ): array {
        return [
            // Image labeling has no shuffle setting: the bank arrives in the
            // order compiling chose, which is by label.
            ...self::shell($blockId, $pageId, $completionType, false),
            // Only the image's own text is spoken, so bank labels have no
            // segment to highlight.
            'items' => self::items($config['bank'] ?? [], fn (string $id) => null),
            // Percentages, never pixels: the marker keeps its place on the
            // diagram at any width and any zoom.
            'slots' => self::slots(
                $config['hotspots'] ?? [],
                'placement.point',
                fn (array $hotspot) => [
                    'x' => (float) ($hotspot['x_pct'] ?? 50),
                    'y' => (float) ($hotspot['y_pct'] ?? 50),
                ]
            ),
        ];
    }

    /** @return array<string, mixed> */
    private static function shell(
        string $blockId,
        string $pageId,
        string $completionType,
        bool $shuffle
    ): array {
        return [
            'blockId' => $blockId,
            'pageId' => $pageId,
            'completionType' => $completionType,
            'shuffle' => $shuffle,
            'strings' => self::strings(),
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $bank
     * @param callable(string): ?string $speechId
     * @return list<array{id: string, label: string, name: string, speechId: ?string}>
     */
    private static function items(array $bank, callable $speechId): array
    {
        $labels = array_map(fn (array $item) => (string) ($item['label'] ?? ''), $bank);
        $totals = array_count_values($labels);
        $seen = [];

        return array_values(array_map(function (array $item) use ($totals, &$seen, $speechId) {
            $id = (string) ($item['id'] ?? '');
            $label = (string) ($item['label'] ?? '');
            $seen[$label] = ($seen[$label] ?? 0) + 1;

            return [
                'id' => $id,
                'label' => $label,
                // Two items may legitimately share a label. Their accessible
                // names and announcements still have to differ, or a student
                // hears "Arc placed" twice with no way to tell which is which.
                'name' => ($totals[$label] ?? 1) > 1
                    ? __('placement.item_repeated', ['label' => $label, 'number' => $seen[$label]])
                    : $label,
                'speechId' => $speechId($id),
            ];
        }, $bank));
    }

    /**
     * @param array<int, array<string, mixed>> $slots
     * @param ?callable(array<string, mixed>): array<string, mixed> $extra
     * @return list<array<string, mixed>>
     */
    private static function slots(array $slots, string $nameKey, ?callable $extra = null): array
    {
        return array_values(array_map(function (array $slot, int $index) use ($nameKey, $extra) {
            // A hotspot carries the number a student sees on the diagram; a
            // matching row is numbered by its place in the list.
            $number = (int) ($slot['number'] ?? $index + 1);

            return [
                'id' => (string) ($slot['id'] ?? ''),
                'number' => $number,
                'name' => __($nameKey, ['number' => $number]),
                'description' => (string) ($slot['description'] ?? ''),
                ...($extra === null ? [] : $extra($slot)),
            ];
        }, $slots, array_keys($slots)));
    }

    /** @return array<string, string> */
    private static function strings(): array
    {
        return array_combine(
            self::STRINGS,
            array_map(fn (string $key) => __("placement.{$key}"), self::STRINGS)
        );
    }
}
