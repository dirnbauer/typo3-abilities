<?php

declare(strict_types=1);

namespace Webconsulting\Abilities\Policy;

use Symfony\Component\Yaml\Yaml;
use Webconsulting\Abilities\Domain\AbilityDefinition;
use Webconsulting\Abilities\Domain\ExecutionContext;
use Webconsulting\Abilities\Domain\RiskTier;

/**
 * Site-wide execution policy for abilities, mirroring the semantics of
 * typo3-capability-manifest's PolicyChecker: deny rules block outright,
 * review_required rules block unless the execution context carries an
 * explicit human approval, and max_risk_tier caps the tier.
 *
 * Rule entry grammar (deny / review_required lists):
 *   "namespace/ability-name"     exact ability name
 *   "namespace/*"                every ability of a namespace
 *   "*"                          every ability
 *   "risk:high"                  abilities at exactly this risk tier
 *   "scope:news:write"           abilities requiring this scope (prefix match)
 *   "side-effect:network"        abilities with this side effect (prefix match)
 */
final readonly class AbilityPolicy
{
    /**
     * @param list<string> $deny
     * @param list<string> $reviewRequired
     */
    private function __construct(
        public string $name,
        private array $deny,
        private array $reviewRequired,
        private ?RiskTier $maxRiskTier,
    ) {
    }

    public static function allowAll(): self
    {
        return new self('allow-all (no policy file)', [], [], null);
    }

    /**
     * @param array<mixed> $data parsed abilities-policy.yaml content
     */
    public static function fromArray(array $data): self
    {
        $policy = is_array($data['policy'] ?? null) ? $data['policy'] : [];

        $maxRiskTier = null;
        if (is_string($policy['max_risk_tier'] ?? null)) {
            $maxRiskTier = RiskTier::tryFrom($policy['max_risk_tier'])
                ?? throw new \InvalidArgumentException(
                    sprintf(
                        'Invalid max_risk_tier "%s" in abilities policy; expected one of: low, medium, high, critical.',
                        $policy['max_risk_tier'],
                    ),
                    7480291006,
                );
        }

        return new self(
            name: is_string($policy['name'] ?? null) ? $policy['name'] : 'unnamed policy',
            deny: array_values(array_filter((array)($policy['deny'] ?? []), is_string(...))),
            reviewRequired: array_values(array_filter((array)($policy['review_required'] ?? []), is_string(...))),
            maxRiskTier: $maxRiskTier,
        );
    }

    public static function fromFile(string $file): self
    {
        if (!is_file($file)) {
            return self::allowAll();
        }

        $data = Yaml::parseFile($file);

        return self::fromArray(is_array($data) ? $data : []);
    }

    public function decide(AbilityDefinition $definition, ExecutionContext $context): PolicyDecision
    {
        foreach ($this->deny as $rule) {
            if ($this->matches($rule, $definition)) {
                return PolicyDecision::deny(
                    sprintf('Ability "%s" is denied by rule "%s" of policy "%s".', $definition->name, $rule, $this->name),
                );
            }
        }

        if ($this->maxRiskTier !== null && $definition->riskTier->exceeds($this->maxRiskTier)) {
            return PolicyDecision::deny(
                sprintf(
                    'Ability "%s" has risk tier "%s", above the maximum "%s" allowed by policy "%s".',
                    $definition->name,
                    $definition->riskTier->value,
                    $this->maxRiskTier->value,
                    $this->name,
                ),
            );
        }

        foreach ($this->reviewRequired as $rule) {
            if ($this->matches($rule, $definition) && !$context->reviewApproved) {
                return PolicyDecision::deny(
                    sprintf(
                        'Ability "%s" requires human review per rule "%s" of policy "%s" and the execution context carries no approval.',
                        $definition->name,
                        $rule,
                        $this->name,
                    ),
                );
            }
        }

        return PolicyDecision::allow();
    }

    private function matches(string $rule, AbilityDefinition $definition): bool
    {
        if (str_starts_with($rule, 'risk:')) {
            return $definition->riskTier->value === substr($rule, 5);
        }

        if (str_starts_with($rule, 'scope:')) {
            return $this->anyMatchesPrefix($definition->scopes, substr($rule, 6));
        }

        if (str_starts_with($rule, 'side-effect:')) {
            return $this->anyMatchesPrefix($definition->sideEffects, substr($rule, 12));
        }

        if ($rule === '*') {
            return true;
        }

        if (str_ends_with($rule, '/*')) {
            return str_starts_with($definition->name, substr($rule, 0, -1));
        }

        return $definition->name === $rule;
    }

    /**
     * @param list<string> $values
     */
    private function anyMatchesPrefix(array $values, string $pattern): bool
    {
        foreach ($values as $value) {
            if ($value === $pattern || str_starts_with($value, $pattern . ':')) {
                return true;
            }
        }

        return false;
    }
}
