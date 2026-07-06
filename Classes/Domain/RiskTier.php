<?php

declare(strict_types=1);

namespace Webconsulting\Abilities\Domain;

/**
 * Governance risk tier of an ability.
 *
 * The vocabulary and scores mirror typo3-capability-manifest risk levels
 * (low=0, medium=5, high=10, critical=15) so ability policies and
 * extension capability policies speak the same language.
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
