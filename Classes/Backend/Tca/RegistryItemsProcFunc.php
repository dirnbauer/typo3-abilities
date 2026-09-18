<?php

declare(strict_types=1);

namespace Webconsulting\Abilities\Backend\Tca;

use Webconsulting\Abilities\Domain\ExecutionContext;
use Webconsulting\Abilities\Registry\AbilitiesRegistry;

/**
 * TCA itemsProcFuncs fed by the live registry, so editors pick from what is
 * actually registered instead of typing identifiers by hand:
 *  - be_groups.tx_abilities_scopes: every scope an ability declares
 *  - sys_reaction.tx_abilities_ability: every ability a webhook may run
 */
final class RegistryItemsProcFunc
{
    public function __construct(
        private readonly AbilitiesRegistry $registry,
    ) {}

    /**
     * @param array<string, mixed> $parameters
     */
    public function addAbilityScopes(array &$parameters): void
    {
        $items = is_array($parameters['items'] ?? null) ? $parameters['items'] : [];
        foreach ($this->registry->getDeclaredScopes() as $scope) {
            $items[] = ['label' => $scope, 'value' => $scope];
        }
        $parameters['items'] = $items;
    }

    /**
     * Webhooks are HTTP clients like REST callers: only REST-exposed abilities are offered.
     *
     * @param array<string, mixed> $parameters
     */
    public function addAbilities(array &$parameters): void
    {
        $items = is_array($parameters['items'] ?? null) ? $parameters['items'] : [];
        foreach ($this->registry->getDefinitions(null, ExecutionContext::SURFACE_REST) as $definition) {
            $items[] = ['label' => sprintf('%s — %s', $definition->name, $definition->title), 'value' => $definition->name];
        }
        $parameters['items'] = $items;
    }
}
