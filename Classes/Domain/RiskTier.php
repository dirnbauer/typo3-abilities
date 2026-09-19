<?php

declare(strict_types=1);

namespace Webconsulting\Abilities\Domain;

/**
 * Governance risk tier of an ability.
 *
 * The vocabulary and scores mirror the risk levels of the MCP capability
 * manifest (low=0, medium=5, high=10, critical=15), so an abilities policy
 * and a capability manifest score the same action the same way.
 */
enum RiskTier: string
{
    case Low = 'low';
    case Medium = 'medium';
    case High = 'high';
    case Critical = 'critical';

    public function score(): int
    {
        return match ($this) {
            self::Low => 0,
            self::Medium => 5,
            self::High => 10,
            self::Critical => 15,
        };
    }

    public function exceeds(self $maximum): bool
    {
        return $this->score() > $maximum->score();
    }
}
