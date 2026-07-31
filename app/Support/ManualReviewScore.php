<?php

namespace App\Support;

/**
 * Shared score shape for manual short_response/cer reviews.
 * Teacher and student surfaces both format through here so percentages match.
 */
final readonly class ManualReviewScore
{
    public function __construct(
        public int $awarded,
        public int $possible,
        public ?int $percentage,
    ) {}

    public static function fromAwardedAndPossible(int $awarded, int $possible): self
    {
        $percentage = $possible > 0
            ? (int) round(($awarded / $possible) * 100)
            : null;

        return new self($awarded, $possible, $percentage);
    }

    /**
     * @return array{awarded: int, possible: int, percentage: int}|null
     */
    public function toArray(): ?array
    {
        if ($this->percentage === null) {
            return null;
        }

        return [
            'awarded' => $this->awarded,
            'possible' => $this->possible,
            'percentage' => $this->percentage,
        ];
    }
}
