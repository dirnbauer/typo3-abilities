<?php

declare(strict_types=1);

namespace Webconsulting\Abilities\Policy;

final readonly class PolicyDecision
{
    private function __construct(
        public bool $allowed,
        public ?string $reason,
    ) {
    }

    public static function allow(): self
    {
        return new self(true, null);
    }

    public static function deny(string $reason): self
    {
        return new self(false, $reason);
    }
}
