<?php

namespace App\View\Components\Player;

use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Log;
use Illuminate\View\Component;

/**
 * Renders one manifest block by convention: a block of type "static_table"
 * is drawn by lesson-player/blocks/static_table.blade.php. Adding a block
 * renderer therefore means adding a partial, never editing a switch here.
 *
 * A type with no partial (an activity type before Phase 2B, or a type this
 * build no longer knows) is logged for staff and replaced with a neutral
 * placeholder. Students never see the type key or any other internal.
 */
class Block extends Component
{
    private const PARTIAL_NAMESPACE = 'lesson-player.blocks.';

    private const FALLBACK_PARTIAL = 'lesson-player.blocks.unavailable';

    public string $blockId;

    public string $type;

    /** @var array<string, mixed> */
    public array $config;

    /** @var list<array{id: string, label: ?string, text: string}> */
    public array $speech;

    public string $partial;

    /**
     * @param array<string, mixed> $block a redacted manifest block
     */
    public function __construct(public array $block, public string $pageId = '')
    {
        $this->blockId = (string) ($block['block_id'] ?? '');
        $this->type = (string) ($block['type'] ?? '');
        $this->config = is_array($block['config'] ?? null) ? $block['config'] : [];
        $this->speech = is_array($block['speech'] ?? null) ? $block['speech'] : [];
        $this->partial = $this->resolvePartial();
    }

    private function resolvePartial(): string
    {
        $candidate = self::PARTIAL_NAMESPACE . $this->type;

        // Only a well-formed type key may name a view, so a malformed or
        // hostile type string can never be used to reach another template.
        $isValidKey = preg_match('/^[a-z][a-z0-9_]*$/', $this->type) === 1;

        if ($isValidKey && view()->exists($candidate)) {
            return $candidate;
        }

        Log::warning('Lesson player has no renderer for a block type.', [
            'block_id' => $this->blockId,
            'type' => $this->type,
        ]);

        return self::FALLBACK_PARTIAL;
    }

    public function render(): View
    {
        return view('components.player.block');
    }
}
