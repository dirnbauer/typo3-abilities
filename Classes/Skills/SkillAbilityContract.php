<?php

declare(strict_types=1);

namespace Webconsulting\Abilities\Skills;

use Webconsulting\Abilities\Domain\ExecutionContext;
use Webconsulting\Abilities\Policy\PolicyProvider;
use Webconsulting\Abilities\Registry\AbilitiesRegistry;

/**
 * The contract between agent skills (webconsulting/skillflow) and the
 * registry: a skill declares the abilities it needs by name; this service
 * resolves them to the MCP tool identifiers an agent client sees
 * (mcp__typo3__ability_<ns>_<name>) and validates the declaration against
 * the registry and the site policy before a skill is installed or run.
 */
final class SkillAbilityContract
{
    public const MCP_TOOL_PREFIX = 'mcp__typo3__';

    public const FINDING_MISSING = 'missing';
    public const FINDING_NOT_EXPOSED = 'not_exposed';
    public const FINDING_POLICY_DENIED = 'policy_denied';
    public const FINDING_REVIEW_REQUIRED = 'review_required';

    public function __construct(
        private readonly AbilitiesRegistry $registry,
        private readonly PolicyProvider $policyProvider,
    ) {
    }

    /**
     * @param list<string> $abilityNames
     * @return array<string, string> ability name => MCP client tool name; unknown abilities are omitted (see validate())
     */
    public function resolveMcpToolNames(array $abilityNames): array
    {
        $resolved = [];
        foreach (array_unique($abilityNames) as $name) {
            if ($this->registry->has($name)) {
                $resolved[$name] = self::MCP_TOOL_PREFIX . $this->registry->getDefinition($name)->mcpToolName();
            }
        }

        return $resolved;
    }

    /**
     * @param list<string> $abilityNames
     * @return list<array{ability: string, code: string, message: string}> empty when every ability is available to an MCP session
     */
    public function validate(array $abilityNames): array
    {
        $findings = [];
        $policy = $this->policyProvider->get();
        $context = ExecutionContext::mcp();

        foreach (array_unique($abilityNames) as $name) {
            if (!$this->registry->has($name)) {
                $findings[] = [
                    'ability' => $name,
                    'code' => self::FINDING_MISSING,
                    'message' => sprintf('Ability "%s" is not registered in this installation.', $name),
                ];
                continue;
            }

            $definition = $this->registry->getDefinition($name);
            if (!$definition->isExposedTo(ExecutionContext::SURFACE_MCP)) {
                $findings[] = [
                    'ability' => $name,
                    'code' => self::FINDING_NOT_EXPOSED,
                    'message' => sprintf('Ability "%s" is not exposed to the MCP surface (expose: %s).', $name, implode(', ', $definition->expose) ?: 'none'),
                ];
                continue;
            }

            $decision = $policy->decide($definition, $context);
            if (!$decision->allowed) {
                $findings[] = [
                    'ability' => $name,
                    'code' => $decision->reviewRequired ? self::FINDING_REVIEW_REQUIRED : self::FINDING_POLICY_DENIED,
                    'message' => $decision->reason ?? 'Denied by policy.',
                ];
            }
        }

        return $findings;
    }
}
