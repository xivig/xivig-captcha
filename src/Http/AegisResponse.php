<?php

declare(strict_types=1);

namespace Aegis\Captcha\Http;

use JsonSerializable;

/**
 * Strongly-typed verification response value object.
 */
final class AegisResponse implements JsonSerializable
{
    /**
     * @param list<string> $flags
     * @param array<string, mixed> $details
     */
    public function __construct(
        public readonly bool $isHuman,
        public readonly float $score,
        public readonly array $flags = [],
        public readonly array $details = []
    ) {
    }

    public static function human(float $score = 1.0, array $flags = [], array $details = []): self
    {
        return new self(true, $score, $flags, $details);
    }

    public static function bot(float $score = 0.0, array $flags = [], array $details = []): self
    {
        return new self(false, $score, $flags, $details);
    }

    public function jsonSerialize(): array
    {
        return [
            'success' => $this->isHuman,
            'is_human' => $this->isHuman,
            'score' => $this->score,
            'flags' => $this->flags,
            'details' => $this->details,
        ];
    }
}
