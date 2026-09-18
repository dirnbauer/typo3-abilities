<?php

declare(strict_types=1);

namespace Webconsulting\Abilities\Attribute;

use Webconsulting\Abilities\Domain\ExecutionContext;
use Webconsulting\Abilities\Domain\RiskTier;

/**
 * Declares a class as an ability: one typed, permissioned unit of
 * functionality in the installation-wide abilities registry.
 *
 * The attribute carries the registry schema — name, scopes, risk tier,
 * side effects, annotations, projection surfaces — while the input/output
 * contract lives on the class itself (AbilityInterface::getInputSchema() /
 * getOutputSchema()) so schemas may be computed at runtime.
 *
 * Annotations follow the WordPress Abilities API vocabulary (readonly,
 * destructive, idempotent, instructions) and are projected onto MCP tool
 * annotations and the REST run method (GET / POST / DELETE).
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class AsAbility
{
    private const NAME_PATTERN = '/^[a-z0-9][a-z0-9\-]*\/[a-z0-9][a-z0-9\-]*$/';

    /**
     * @param string $name Unique ability name, "namespace/ability-name" (lowercase kebab-case both sides)
     * @param string $title Human-readable label
     * @param string $description What the ability does — written for agents and humans alike
     * @param string $category Category slug, e.g. "content", "system", "site" (see CategoryRegistry)
     * @param list<string> $scopes Required token scopes, "resource:operation" convention (e.g. "news:write")
     * @param RiskTier $riskTier Governance risk tier; policies can cap the maximum allowed tier
     * @param list<string> $sideEffects Capability-manifest subsystem vocabulary (e.g. "database:write", "network:outbound"); empty = read-only
     * @param bool $idempotent Safe to execute repeatedly with the same input
     * @param bool $destructive Deletes or irreversibly alters data
     * @param list<string> $expose Projection surfaces this ability may appear on ("mcp", "cli", "rest")
     * @param array<string, mixed> $meta Free-form additional metadata
     * @param bool|null $readOnly Explicit read-only annotation; null derives it from $sideEffects (empty = read-only)
     * @param string $instructions Usage guidance for agents (when and how to call this ability)
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
        public readonly array $expose = ExecutionContext::PROJECTION_SURFACES,
        public readonly array $meta = [],
        public readonly ?bool $readOnly = null,
        public readonly string $instructions = '',
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

    /**
     * Effective read-only annotation: the explicit value when given,
     * otherwise "declares no side effects".
     */
    public function isReadOnly(): bool
    {
        return $this->readOnly ?? $this->sideEffects === [];
    }
}
