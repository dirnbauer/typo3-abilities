<?php

declare(strict_types=1);

namespace Webconsulting\Abilities\Ability;

use Symfony\Component\DependencyInjection\Attribute\AutowireServiceClosure;
use Webconsulting\Abilities\Attribute\AsAbility;
use Webconsulting\Abilities\Catalog\AbilityCatalog;
use Webconsulting\Abilities\Catalog\CatalogEntry;
use Webconsulting\Abilities\Domain\ExecutionContext;
use Webconsulting\Abilities\Domain\RiskTier;
use Webconsulting\Abilities\Registry\AbstractAbility;

/**
 * The ability catalogue as an ability, so an agent discovers everything
 * the installation can do through the same door it uses for everything else
 * (MCP tool ability_abilities_catalog, REST GET .../abilities/catalog/run,
 * CLI abilities:run abilities/catalog).
 *
 * The catalogue is injected as a service closure: its abilities source reads
 * the registry that instantiates this ability, so a direct dependency would
 * be circular.
 */
#[AsAbility(
    name: 'abilities/catalog',
    title: 'Ability catalogue',
    description: 'Lists everything this TYPO3 installation can do — abilities, native MCP tools, agent skills, REST and webhook endpoints and console commands — as one uniform catalogue: id, title, description, source, surfaces, input schema, annotations and how to invoke each entry from each surface.',
    category: 'registry',
    scopes: ['abilities:read'],
    riskTier: RiskTier::Low,
    sideEffects: [],
    idempotent: true,
    readOnly: true,
    instructions: 'Call this first to learn what the site offers. Narrow with "source" (abilities, mcp, skills, rest, cli), "surface" (mcp, cli, rest, webhook, scheduler, skills, php, frontend) or a free-text "search". Then invoke an entry the way its "invocations" describe for your surface; for abilities, call abilities/describe to get the exact input schema.',
)]
final class CatalogAbility extends AbstractAbility
{
    /**
     * @param \Closure(): AbilityCatalog $catalog
     */
    public function __construct(
        #[AutowireServiceClosure(AbilityCatalog::class)]
        private readonly \Closure $catalog,
    ) {}

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => [
                'source' => [
                    'type' => 'string',
                    'enum' => [
                        CatalogEntry::SOURCE_ABILITIES,
                        CatalogEntry::SOURCE_MCP,
                        CatalogEntry::SOURCE_SKILLS,
                        CatalogEntry::SOURCE_REST,
                        CatalogEntry::SOURCE_CLI,
                    ],
                    'description' => 'Only entries of this source',
                ],
                'surface' => ['type' => 'string', 'description' => 'Only entries invocable from this surface (mcp, cli, rest, webhook, scheduler, skills, php, frontend)'],
                'search' => ['type' => 'string', 'maxLength' => 200, 'default' => '', 'description' => 'Case-insensitive substring over id, title and description'],
            ],
        ];
    }

    public function getOutputSchema(): array
    {
        return [
            'type' => 'object',
            'required' => ['entries', 'total', 'sources'],
            'properties' => [
                'entries' => ['type' => 'array', 'items' => ['type' => 'object']],
                'total' => ['type' => 'integer'],
                'sources' => ['type' => 'object', 'description' => 'Entry count per source'],
            ],
        ];
    }

    public function execute(array $input, ExecutionContext $context): mixed
    {
        return ($this->catalog)()->toArray(
            is_string($input['source'] ?? null) && $input['source'] !== '' ? $input['source'] : null,
            is_string($input['surface'] ?? null) && $input['surface'] !== '' ? $input['surface'] : null,
            is_string($input['search'] ?? null) ? $input['search'] : '',
        );
    }
}
