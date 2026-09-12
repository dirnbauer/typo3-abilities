<?php

declare(strict_types=1);

namespace Webconsulting\Abilities\Ability;

use Symfony\Component\DependencyInjection\Attribute\AutowireServiceClosure;
use Webconsulting\Abilities\Attribute\AsAbility;
use Webconsulting\Abilities\Domain\AbilityDefinition;
use Webconsulting\Abilities\Domain\ExecutionContext;
use Webconsulting\Abilities\Domain\RiskTier;
use Webconsulting\Abilities\Registry\AbilitiesRegistry;
use Webconsulting\Abilities\Registry\AbstractAbility;

/**
 * Registry introspection as an ability, so discovery works on every
 * projection — including MCP, where it appears as ability_abilities_list.
 *
 * The registry is injected as a service closure: the registry instantiates
 * its abilities while being built, so a direct dependency would be circular.
 */
#[AsAbility(
    name: 'abilities/list',
    title: 'List abilities',
    description: 'Lists the abilities registered in this TYPO3 installation with their governance metadata (category, scopes, risk tier, annotations, surfaces), optionally filtered by category or surface.',
    category: 'registry',
    scopes: ['abilities:read'],
    riskTier: RiskTier::Low,
    sideEffects: [],
    idempotent: true,
    readOnly: true,
    instructions: 'Call this first to discover what the site can do, then call abilities/describe to get the input schema of the ability you want to run.',
)]
final class ListAbilitiesAbility extends AbstractAbility
{
    /**
     * @param \Closure(): AbilitiesRegistry $registry
     */
    public function __construct(
        #[AutowireServiceClosure(AbilitiesRegistry::class)]
        private readonly \Closure $registry,
    ) {
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'category' => ['type' => 'string', 'description' => 'Only abilities of this category slug'],
                'surface' => [
                    'type' => 'string',
                    'enum' => [ExecutionContext::SURFACE_MCP, ExecutionContext::SURFACE_CLI, ExecutionContext::SURFACE_REST],
                    'description' => 'Only abilities exposed to this surface',
                ],
            ],
            'additionalProperties' => false,
        ];
    }

    public function getOutputSchema(): array
    {
        return [
            'type' => 'object',
            'required' => ['abilities', 'total'],
            'properties' => [
                'abilities' => ['type' => 'array', 'items' => ['type' => 'object']],
                'total' => ['type' => 'integer'],
            ],
        ];
    }

    public function execute(array $input, ExecutionContext $context): mixed
    {
        $category = is_string($input['category'] ?? null) && $input['category'] !== '' ? $input['category'] : null;
        $surface = is_string($input['surface'] ?? null) && $input['surface'] !== '' ? $input['surface'] : null;

        $abilities = array_values(array_map(
            static fn(AbilityDefinition $definition): array => $definition->toArray(),
            ($this->registry)()->getDefinitions($category, $surface),
        ));

        return ['abilities' => $abilities, 'total' => count($abilities)];
    }
}
