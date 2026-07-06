<?php

declare(strict_types=1);

namespace Webconsulting\Abilities\Attribute;

use Webconsulting\Abilities\Domain\RiskTier;

/**
 * Declares a class as an ability: one typed, permissioned unit of
 * functionality in the installation-wide abilities registry.
 *
 * The attribute carries the registry schema — name, scopes, risk tier,
 * side effects, projection surfaces — while the input/output contract
 * lives on the class itself (AbilityInterface::getInputSchema() /
 * getOutputSchema()) so schemas may be computed at runtime.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class AsAbility
{
    private const NAME_PATTERN = '/^[a-z0-9][a-z0-9\-]*\/[a-z0-9][a-z0-9\-]*$/';

    /**
     * @param string $name Unique ability name, "namespace/ability-name" (lowercase kebab-case both sides)
     * @param string $title Human-readable label
     * @param string $description What the ability does — written for agents and humans alike
     * @param string $category Grouping key, e.g. "content", "system", "site"
     * @param list<string> $scopes Required token scopes, "resource:operation" convention (e.g. "news:write")
     * @param RiskTier $riskTier Governance risk tier; policies can cap the maximum allowed tier
     * @param list<string> $sideEffects Capability-manifest subsystem vocabulary (e.g. "database:write", "network:outbound"); empty = read-only
     * @param bool $idempotent Safe to execute repeatedly with the same input
     * @param bool $destructive Deletes or irreversibly alters data
     * @param list<string> $expose Projection surfaces this ability may appear on ("mcp", "cli", "rest")
     * @param array<string, mixed> $meta Free-form additional metadata
     */
    public function __construct(
        public readonly string $name,
        public readonly string $title,
        public readonly string $description,
        public readonly string $category = 'general',
        public readonly array $scopes = [],
        public readonly RiskTier $riskTier = RiskTier::Low,
        public readonly array $sideEffects = [],
        public readonly bool $idempotent = false,
        public readonly bool $destructive = false,
        public readonly array $expose = ['mcp', 'cli', 'rest'],
        public readonly array $meta = [],
    ) {
        if (preg_match(self::NAME_PATTERN, $name) !== 1) {
            throw new \InvalidArgumentException(
                sprintf(
                    'Ability name "%s" is invalid. Expected "namespace/ability-name" in lowercase kebab-case, e.g. "news/create-article".',
                    $name,
                ),
                7480291001,
            );
        }
    }
}
