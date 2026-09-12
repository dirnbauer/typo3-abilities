<?php

declare(strict_types=1);

namespace Webconsulting\Abilities\Event;

use Webconsulting\Abilities\Domain\AbilityDefinition;
use Webconsulting\Abilities\Domain\RiskTier;

/**
 * Dispatched by the AbilitiesRegistry for every ability definition it
 * builds. Listeners can tighten or loosen governance facts per
 * installation without touching the ability class: hide an ability from a
 * surface, raise its risk tier, or declare it read-only.
 *
 * Listeners must not depend on the AbilitiesRegistry itself — it is being
 * constructed while this event is dispatched.
 */
final class ModifyAbilityDefinitionEvent
{
    public function __construct(
        private AbilityDefinition $definition,
    ) {}

    public function getDefinition(): AbilityDefinition
    {
        return $this->definition;
    }

    public function setDefinition(AbilityDefinition $definition): void
    {
        if ($definition->name !== $this->definition->name || $definition->className !== $this->definition->className) {
            throw new \LogicException(
                sprintf('ModifyAbilityDefinitionEvent may not replace ability "%s" with a different ability.', $this->definition->name),
                7480291012,
            );
        }
        $this->definition = $definition;
    }

    /**
     * @param list<string> $expose
     */
    public function setExpose(array $expose): void
    {
        $this->definition = $this->definition->with(expose: $expose);
    }

    public function setRiskTier(RiskTier $riskTier): void
    {
        $this->definition = $this->definition->with(riskTier: $riskTier);
    }

    public function setReadOnly(bool $readOnly): void
    {
        $this->definition = $this->definition->with(readOnly: $readOnly);
    }
}
