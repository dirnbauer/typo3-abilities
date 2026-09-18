<?php

declare(strict_types=1);

namespace Webconsulting\Abilities\Ability;

use Symfony\Component\DependencyInjection\Attribute\AutowireServiceClosure;
use Webconsulting\Abilities\Attribute\AsAbility;
use Webconsulting\Abilities\Domain\ExecutionContext;
use Webconsulting\Abilities\Domain\RiskTier;
use Webconsulting\Abilities\Registry\AbilitiesRegistry;
use Webconsulting\Abilities\Registry\AbstractAbility;

/**
 * Full contract of one ability — definition plus input/output JSON Schemas —
 * as an ability (MCP tool ability_abilities_describe).
 */
#[AsAbility(
    name: 'abilities/describe',
    title: 'Describe ability',
    description: 'Returns the full definition of one ability including its input and output JSON Schemas.',
    category: 'registry',
    scopes: ['abilities:read'],
    riskTier: RiskTier::Low,
    sideEffects: [],
    idempotent: true,
    readOnly: true,
    instructions: 'Use the "name" from abilities/list (e.g. "system/site-info"); the returned inputSchema tells you exactly which arguments the ability accepts.',
)]
final class DescribeAbilityAbility extends AbstractAbility
{
    /**
     * @param \Closure(): AbilitiesRegistry $registry
     */
    public function __construct(
        #[AutowireServiceClosure(AbilitiesRegistry::class)]
        private readonly \Closure $registry,
    ) {}

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'required' => ['name'],
            'properties' => [
                'name' => [
                    'type' => 'string',
                    'pattern' => '^[a-z0-9][a-z0-9-]*/[a-z0-9][a-z0-9-]*$',
                    'description' => 'Ability name, "namespace/ability-name"',
                ],
            ],
            'additionalProperties' => false,
        ];
    }

    public function getOutputSchema(): array
    {
        return [
            'type' => 'object',
            'required' => ['name', 'inputSchema', 'outputSchema'],
            'properties' => [
                'name' => ['type' => 'string'],
                'inputSchema' => ['type' => 'object'],
                'outputSchema' => ['type' => 'object'],
            ],
        ];
    }

    public function execute(array $input, ExecutionContext $context): mixed
    {
        $name = is_string($input['name'] ?? null) ? $input['name'] : '';
        $registry = ($this->registry)();
        if (!$registry->has($name)) {
            throw new \OutOfBoundsException(sprintf('Unknown ability "%s".', $name), 7480291030);
        }

        return $registry->describe($name);
    }
}
