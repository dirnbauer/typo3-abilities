<?php

declare(strict_types=1);

namespace Webconsulting\Abilities\Permission;

use Webconsulting\Abilities\Registry\AbilitiesRegistry;

/**
 * TCA itemsProcFunc for be_groups.tx_abilities_scopes: offers every scope
 * declared by a registered ability, so group editors pick from the live
 * registry instead of typing "resource:operation" strings by hand.
 */
final class ScopeItemsProcFunc
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
}
