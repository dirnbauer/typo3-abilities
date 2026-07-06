<?php

declare(strict_types=1);

namespace Webconsulting\Abilities\Event;

use Webconsulting\Abilities\Domain\AbilityDefinition;
use Webconsulting\Abilities\Domain\AbilityResult;
use Webconsulting\Abilities\Domain\ExecutionContext;

/**
 * Dispatched after every ability execution attempt — including denied and
 * failed ones; governance wants the denials most of all. This event is the
 * abilities lane of the unified agent trace store (strategy item 13):
 * listeners persist traces, meter cost, or feed eval sets.
 *
 * $input is the caller's raw input (before schema defaults) — the honest
 * audit record of what was requested.
 */
final readonly class AbilityExecutedEvent
{
    /**
     * @param array<string, mixed> $input
     */
    public function __construct(
        public AbilityDefinition $definition,
        public ExecutionContext $context,
        public array $input,
        public AbilityResult $result,
        public float $durationMs,
    ) {
    }
}
