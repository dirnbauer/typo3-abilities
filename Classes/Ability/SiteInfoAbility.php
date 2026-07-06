<?php

declare(strict_types=1);

namespace Webconsulting\Abilities\Ability;

use TYPO3\CMS\Core\Information\Typo3Version;
use TYPO3\CMS\Core\Site\SiteFinder;
use Webconsulting\Abilities\Attribute\AsAbility;
use Webconsulting\Abilities\Domain\ExecutionContext;
use Webconsulting\Abilities\Domain\RiskTier;
use Webconsulting\Abilities\Registry\AbstractAbility;

/**
 * Reference ability: read-only site inventory. Exists so a fresh install
 * has one registry entry to list, describe, run and see projected as the
 * MCP tool "ability_system_site-info".
 */
#[AsAbility(
    name: 'system/site-info',
    title: 'Site info',
    description: 'Lists the configured sites of this installation (identifier, root page, base URL, languages) and the TYPO3 version.',
    category: 'system',
    scopes: ['system:read'],
    riskTier: RiskTier::Low,
    sideEffects: [],
    idempotent: true,
)]
final class SiteInfoAbility extends AbstractAbility
{
    public function __construct(
        private readonly SiteFinder $siteFinder,
    ) {
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [],
            'additionalProperties' => false,
        ];
    }

    public function getOutputSchema(): array
    {
        return [
            'type' => 'object',
            'required' => ['typo3Version', 'sites'],
            'properties' => [
                'typo3Version' => ['type' => 'string'],
                'sites' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'required' => ['identifier', 'rootPageId', 'base'],
                        'properties' => [
                            'identifier' => ['type' => 'string'],
                            'rootPageId' => ['type' => 'integer'],
                            'base' => ['type' => 'string'],
                            'languages' => [
                                'type' => 'array',
                                'items' => ['type' => 'string'],
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }

    public function execute(array $input, ExecutionContext $context): mixed
    {
        $sites = [];
        foreach ($this->siteFinder->getAllSites() as $site) {
            $sites[] = [
                'identifier' => $site->getIdentifier(),
                'rootPageId' => $site->getRootPageId(),
                'base' => (string)$site->getBase(),
                'languages' => array_values(array_map(
                    static fn($language): string => $language->getLocale()->getName(),
                    $site->getLanguages(),
                )),
            ];
        }

        return [
            'typo3Version' => (new Typo3Version())->getVersion(),
            'sites' => $sites,
        ];
    }
}
