<?php

declare(strict_types=1);

namespace Webconsulting\Abilities\Projection\Mcp;

use Webconsulting\Abilities\Domain\AbilityDefinition;
use Webconsulting\Abilities\Registry\AbilityInterface;

/**
 * Protocol-neutral description of one ability as an MCP tool: everything a
 * tools/list entry needs (name, description, input schema, annotations)
 * without depending on any MCP SDK type. The MCP server extension turns
 * descriptors into its own tool objects.
 */
final readonly class McpToolDescriptor
{
    /**
     * @param array<string, mixed> $inputSchema JSON Schema of the tool input (always an object schema)
     * @param array{title: string, readOnlyHint: bool, destructiveHint: bool, idempotentHint: bool, openWorldHint: bool} $annotations
     */
    public function __construct(
        public string $name,
        public string $description,
        public array $inputSchema,
        public array $annotations,
        public AbilityDefinition $definition,
    ) {}

    public static function fromAbility(AbilityDefinition $definition, AbilityInterface $ability): self
    {
        $inputSchema = $ability->getInputSchema();
        if ($inputSchema === []) {
            $inputSchema = ['type' => 'object', 'properties' => new \stdClass()];
        }

        $description = sprintf('%s — %s', $definition->title, $definition->description);
        if ($definition->instructions !== '') {
            $description .= "\n\n" . $definition->instructions;
        }

        return new self(
            name: $definition->mcpToolName(),
            description: $description,
            inputSchema: $inputSchema,
            annotations: [
                'title' => $definition->title,
                'readOnlyHint' => $definition->isReadOnly(),
                'destructiveHint' => $definition->destructive,
                'idempotentHint' => $definition->idempotent,
                'openWorldHint' => false,
            ],
            definition: $definition,
        );
    }

    /**
     * The tools/list entry shape.
     *
     * @return array{name: string, description: string, inputSchema: array<string, mixed>, annotations: array<string, bool|string>}
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'description' => $this->description,
            'inputSchema' => $this->inputSchema,
            'annotations' => $this->annotations,
        ];
    }
}
