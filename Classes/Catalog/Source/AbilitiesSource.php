<?php

declare(strict_types=1);

namespace Webconsulting\Abilities\Catalog\Source;

use Webconsulting\Abilities\Catalog\CapabilityEntry;
use Webconsulting\Abilities\Catalog\CapabilitySourceInterface;
use Webconsulting\Abilities\Domain\AbilityDefinition;
use Webconsulting\Abilities\Domain\ExecutionContext;
use Webconsulting\Abilities\Http\RestConfiguration;
use Webconsulting\Abilities\Registry\AbilitiesRegistry;

/**
 * The registry's own abilities as catalogue entries, with one invocation per
 * surface the ability is exposed to plus the PHP call.
 */
final class AbilitiesSource implements CapabilitySourceInterface
{
    public function __construct(
        private readonly AbilitiesRegistry $registry,
        private readonly RestConfiguration $rest,
    ) {}

    public function getSource(): string
    {
        return CapabilityEntry::SOURCE_ABILITIES;
    }

    public function getCapabilities(): iterable
    {
        foreach ($this->registry->getDefinitions() as $definition) {
            yield self::entry($definition, $this->registry->get($definition->name)->getInputSchema(), $this->rest);
        }
    }

    /**
     * @param array<string, mixed> $inputSchema
     */
    public static function entry(AbilityDefinition $definition, array $inputSchema, RestConfiguration $rest): CapabilityEntry
    {
        $invocations = [];
        if ($definition->isExposedTo(ExecutionContext::SURFACE_CLI)) {
            $invocations[ExecutionContext::SURFACE_CLI] = sprintf(
                "vendor/bin/typo3 abilities:run %s --input '%s'",
                $definition->name,
                $inputSchema === [] ? '{}' : self::exampleInput($inputSchema),
            );
        }
        if ($definition->isExposedTo(ExecutionContext::SURFACE_MCP)) {
            $invocations[ExecutionContext::SURFACE_MCP] = $definition->mcpToolName();
        }
        if ($definition->isExposedTo(ExecutionContext::SURFACE_REST) && $rest->enabled) {
            $invocations[ExecutionContext::SURFACE_REST] = sprintf(
                '%s %s/abilities/%s/run',
                $definition->restMethod(),
                $rest->basePath,
                $definition->name,
            );
            $invocations[ExecutionContext::SURFACE_WEBHOOK] = sprintf(
                'EXT:reactions "Run ability" reaction → %s',
                $definition->name,
            );
        }
        $invocations[ExecutionContext::SURFACE_PHP] = sprintf(
            "\$executor->execute(\$registry->get('%1\$s'), \$input, \$context, \$registry->getDefinition('%1\$s'))",
            $definition->name,
        );
        if ($definition->isReadOnly()) {
            $invocations[ExecutionContext::SURFACE_FRONTEND] = sprintf(
                'dataProcessing.10 = Webconsulting\Abilities\DataProcessing\AbilityProcessor; 10.ability = %s',
                $definition->name,
            );
        }

        return new CapabilityEntry(
            id: $definition->name,
            title: $definition->title,
            description: $definition->instructions === ''
                ? $definition->description
                : $definition->description . ' ' . $definition->instructions,
            source: CapabilityEntry::SOURCE_ABILITIES,
            surfaces: array_keys($invocations),
            inputSchema: $inputSchema,
            annotations: CapabilityEntry::annotations($definition->isReadOnly(), $definition->destructive, $definition->idempotent),
            invocations: $invocations,
            meta: [
                'category' => $definition->category,
                'scopes' => $definition->scopes,
                'riskTier' => $definition->riskTier->value,
                'sideEffects' => $definition->sideEffects,
                'className' => $definition->className,
            ],
        );
    }

    /**
     * A JSON object naming the required properties, so the CLI invocation is
     * a template an agent can fill in rather than a bare command name.
     *
     * @param array<string, mixed> $inputSchema
     */
    private static function exampleInput(array $inputSchema): string
    {
        $required = is_array($inputSchema['required'] ?? null) ? $inputSchema['required'] : [];
        $example = [];
        foreach ($required as $property) {
            if (is_string($property)) {
                $example[$property] = '…';
            }
        }

        return (string)json_encode($example === [] ? new \stdClass() : $example, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
