<?php

declare(strict_types=1);

namespace Webconsulting\Abilities\Policy;

final readonly class PolicyDecision
{
    private function __construct(
        public bool $allowed,
        public ?string $reason,
        public bool $reviewRequired,
    ) {}

    public static function allow(): self
    {
        return new self(true, null, false);
    }

    public static function deny(string $reason): self
    {
        return new self(false, $reason, false);
    }

    /**
     * Denied only because the execution context carries no human approval:
     * the same run succeeds once a reviewer approves it.
     */
    public static function reviewRequired(string $reason): self
    {
        return new self(false, $reason, true);
    }
}
