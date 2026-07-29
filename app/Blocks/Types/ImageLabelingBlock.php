<?php

namespace App\Blocks\Types;

use App\Blocks\AbstractBlockType;
use App\Rules\AssetUrl;
use Illuminate\Validation\Validator;

/**
 * Students drag bank items onto numbered hotspots. Hotspot coordinates
 * are percentages (0–100 inclusive), never pixels.
 */
class ImageLabelingBlock extends AbstractBlockType
{
    public function key(): string
    {
        return 'image_labeling';
    }

    public function label(): string
    {
        return 'Image Labeling';
    }

    public function isAutoGradable(): bool
    {
        return true;
    }

    public function collectsResponse(): bool
    {
        return true;
    }

    public function configRules(): array
    {
        return [
            'image_url' => ['required', 'string', new AssetUrl()],
            'image_alt' => ['required', 'string'],
            'long_description' => ['nullable', 'string'],
            'instructions' => ['nullable', 'string'],
            'hotspots' => ['required', 'array', 'min:1'],
            'hotspots.*.id' => ['required', 'string'],
            'hotspots.*.number' => ['required', 'integer', 'min:1'],
            'hotspots.*.x_pct' => ['required', 'numeric', 'between:0,100'],
            'hotspots.*.y_pct' => ['required', 'numeric', 'between:0,100'],
            'hotspots.*.answer_id' => ['required', 'string'],
            'hotspots.*.description' => ['nullable', 'string'],
            'bank' => ['required', 'array', 'min:1'],
            'bank.*.id' => ['required', 'string'],
            'bank.*.label' => ['required', 'string'],
        ];
    }

    protected function afterValidation(Validator $validator, array $config): void
    {
        $hotspots = is_array($config['hotspots'] ?? null) ? $config['hotspots'] : [];
        $bank = is_array($config['bank'] ?? null) ? $config['bank'] : [];

        $this->assertDistinctIds($validator, $hotspots, 'hotspots');
        $this->assertDistinctIds($validator, $bank, 'bank');

        $bankIds = array_column(array_filter($bank, 'is_array'), 'id');

        $seenNumbers = [];

        foreach ($hotspots as $index => $hotspot) {
            if (! is_array($hotspot)) {
                continue;
            }

            $answerId = $hotspot['answer_id'] ?? null;
            if ($answerId !== null && ! in_array($answerId, $bankIds, true)) {
                $validator->errors()->add(
                    "hotspots.{$index}.answer_id",
                    "Hotspot answer_id \"{$answerId}\" does not reference an item in this block's bank."
                );
            }

            // A shared ID would survive redaction and tell a student which
            // item answers the hotspot.
            $hotspotId = $hotspot['id'] ?? null;

            if (is_string($hotspotId) && in_array($hotspotId, $bankIds, true)) {
                $validator->errors()->add(
                    "hotspots.{$index}.id",
                    "Hotspot id \"{$hotspotId}\" is also a bank item id; a shared id would reveal the answer."
                );
            }

            $number = $hotspot['number'] ?? null;
            if ($number !== null) {
                if (isset($seenNumbers[$number])) {
                    $validator->errors()->add(
                        "hotspots.{$index}.number",
                        "Hotspot number {$number} is used more than once; numbers must be unique."
                    );
                }
                $seenNumbers[$number] = true;
            }
        }
    }

    public function defaultConfig(): array
    {
        return [
            'image_url' => 'https://example.com/diagram.png',
            'image_alt' => 'Diagram',
            'long_description' => null,
            'instructions' => 'Drag each label onto the matching numbered point.',
            'hotspots' => [
                [
                    'id' => 'hotspot-1',
                    'number' => 1,
                    'x_pct' => 50.0,
                    'y_pct' => 50.0,
                    'answer_id' => 'bank-1',
                    'description' => null,
                ],
            ],
            'bank' => [
                ['id' => 'bank-1', 'label' => 'Label'],
            ],
        ];
    }

    public function compileConfig(array $validatedConfig): array
    {
        return [
            'image_url' => $validatedConfig['image_url'],
            'image_alt' => $validatedConfig['image_alt'],
            'long_description' => $validatedConfig['long_description'] ?? null,
            'instructions' => $validatedConfig['instructions'] ?? null,
            'hotspots' => array_values(array_map(
                fn (array $h) => [
                    'id' => $h['id'],
                    'number' => (int) $h['number'],
                    'x_pct' => (float) $h['x_pct'],
                    'y_pct' => (float) $h['y_pct'],
                    'answer_id' => $h['answer_id'],
                    'description' => $h['description'] ?? null,
                ],
                $validatedConfig['hotspots']
            )),
            'bank' => $this->orderBankByLabel(array_map(
                fn (array $b) => ['id' => $b['id'], 'label' => $b['label']],
                $validatedConfig['bank']
            )),
        ];
    }

    public function redactConfig(array $compiledConfig): array
    {
        $redacted = $compiledConfig;

        $redacted['hotspots'] = array_map(function (array $hotspot) {
            unset($hotspot['answer_id']);

            return $hotspot;
        }, $redacted['hotspots']);

        return $redacted;
    }

    public function grade(array $compiledConfig, ?array $grading, array $response): ?array
    {
        $placements = $response['placements'] ?? [];

        $details = array_map(function (array $hotspot) use ($placements) {
            $placed = $placements[$hotspot['id']] ?? null;

            return [
                'item_id' => $hotspot['id'],
                'correct' => $placed === $hotspot['answer_id'],
                'feedback' => null,
            ];
        }, $compiledConfig['hotspots']);

        return $this->buildGradingResult(array_values($details), $grading);
    }

    /**
     * Reads the instructions, then the image's alternative text, then the
     * long description. Hotspot descriptions and bank labels are interactive
     * affordances the player announces as the student moves between them.
     */
    public function speakableText(array $redactedConfig): array
    {
        $segments = [];

        $this->pushSegment($segments, 'instructions', 'Instructions', $redactedConfig['instructions'] ?? null);
        $this->pushSegment($segments, 'image_alt', 'Image', $redactedConfig['image_alt'] ?? null);
        $this->pushSegment(
            $segments,
            'long_description',
            'Image description',
            $redactedConfig['long_description'] ?? null
        );

        return $segments;
    }
}
