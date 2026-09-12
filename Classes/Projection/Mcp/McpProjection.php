<?php

declare(strict_types=1);

namespace Webconsulting\Abilities\Projection\Mcp;

use Webconsulting\Abilities\Domain\AbilityErrorCode;
use Webconsulting\Abilities\Domain\AbilityResult;
use Webconsulting\Abilities\Domain\ExecutionContext;
use Webconsulting\Abilities\Execution\AbilityExecutor;
use Webconsulting\Abilities\Registry\AbilitiesRegistry;

/**
 * MCP projection of the registry, free of any MCP SDK symbol: descriptors
 * for tools/list and a tools/call entry point keyed by MCP tool name
 * (ability_<ns>_<name>). The MCP server extension bridges this to its
 * ToolInterface; this extension never needs the server installed.
 *
 * Only abilities exposed to the "mcp" surface are projected. Execution goes
 * through the governed pipeline with the ExecutionContext the caller
 * supplies (usually ExecutionContext::mcp() after the server authenticated
 * and gated the session).
 */
final class McpProjection
{
    public function __construct(
        private readonly AbilitiesRegistry $registry,
        private readonly AbilityExecutor $executor,
    ) {
    }

    /**
     * @return iterable<McpToolDescriptor> sorted by ability name
     */
    public function descriptors(): iterable
    {
        $descriptors = [];
        foreach ($this->registry->getDefinitions(null, ExecutionContext::SURFACE_MCP) as $definition) {
            $descriptors[] = McpToolDescriptor::fromAbility($definition, $this->registry->get($definition->name));
        }

        return $descriptors;
    }

    public function has(string $toolName): bool
    {
        return $this->resolveAbilityName($toolName) !== null;
    }

    public function descriptor(string $toolName): ?McpToolDescriptor
    {
        $abilityName = $this->resolveAbilityName($toolName);
        if ($abilityName === null) {
            return null;
        }

        return McpToolDescriptor::fromAbility(
            $this->registry->getDefinition($abilityName),
            $this->registry->get($abilityName),
        );
    }

    /**
     * Reverse mapping of AbilityDefinition::mcpToolName(): "ability_ns_name"
     * → "ns/name" when such an MCP-exposed ability exists.
     */
    public function resolveAbilityName(string $toolName): ?string
    {
        if (!str_starts_with($toolName, 'ability_')) {
            return null;
        }
        $rest = substr($toolName, strlen('ability_'));
        $separator = strpos($rest, '_');
        if ($separator === false) {
            return null;
        }
        $abilityName = substr($rest, 0, $separator) . '/' . substr($rest, $separator + 1);
        if (!$this->registry->has($abilityName)) {
            return null;
        }

        return $this->registry->getDefinition($abilityName)->isExposedTo(ExecutionContext::SURFACE_MCP) ? $abilityName : null;
    }

    /**
     * @param array<string, mixed> $arguments the tools/call arguments object
     */
    public function execute(string $toolName, array $arguments, ExecutionContext $context): AbilityResult
    {
        $abilityName = $this->resolveAbilityName($toolName);
        if ($abilityName === null) {
            return AbilityResult::failure(
                AbilityErrorCode::NotFound,
                sprintf('No MCP-exposed ability is projected as tool "%s".', $toolName),
            );
        }

        return $this->executor->execute(
            $this->registry->get($abilityName),
            $arguments,
            $context,
            $this->registry->getDefinition($abilityName),
        );
    }
}
