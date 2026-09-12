<?php

declare(strict_types=1);

namespace Webconsulting\Abilities\Event;

use Webconsulting\Abilities\Domain\AbilityDefinition;
use Webconsulting\Abilities\Domain\ExecutionContext;

/**
 * Dispatched before the execution pipeline of an ability starts (before the
 * policy gate). Listeners may rewrite the input or veto the run; a vetoed
 * run fails with ability_policy_denied and nothing executes.
 *
 * Counterpart of WordPress' wp_before_execute_ability hook.
 */
final class BeforeAbilityExecutionEvent
{
    private ?string $denialReason = null;

    /**
     * @param array<string, mixed> $input
     */
    public function __construct(
        public readonly AbilityDefinition $definition,
        public readonly ExecutionContext $context,
        private array $input,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function getInput(): array
    {
        return $this->input;
    }

    /**
     * @param array<string, mixed> $input
     */
    public function setInput(array $input): void
    {
        $this->input = $input;
    }

    public function deny(string $reason): void
    {
        $this->denialReason = $reason;
    }

    public function isDenied(): bool
    {
        return $this->denialReason !== null;
    }

    public function getDenialReason(): ?string
    {
        return $this->denialReason;
    }
}
