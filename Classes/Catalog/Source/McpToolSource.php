<?php

declare(strict_types=1);

namespace Webconsulting\Abilities\Catalog\Source;

use Hn\McpServer\MCP\ToolRegistry;
use Webconsulting\Abilities\Catalog\CapabilityEntry;
use Webconsulting\Abilities\Catalog\CapabilitySourceInterface;
use Webconsulting\Abilities\Domain\ExecutionContext;
use Webconsulting\Abilities\Projection\Mcp\McpProjection;

/**
 * Every native tool of hn/typo3-mcp-server, read from its ToolRegistry with
 * the tool's own schema and annotations. Abilities the server projects as
 * tools (ability_*) are skipped — they are catalogued as abilities. The
 * server extension is optional: without it this source yields nothing.
 */
final class McpToolSource implements CapabilitySourceInterface
{
    public function __construct(
        private readonly McpProjection $projection,
        private readonly ?ToolRegistry $toolRegistry = null,
    ) {}

    public function getSource(): string
    {
        return CapabilityEntry::SOURCE_MCP;
    }

    public function getCapabilities(): iterable
    {
        if ($this->toolRegistry === null || !class_exists(ToolRegistry::class)) {
            return;
        }
        foreach ($this->toolRegistry->getTools() as $tool) {
            $name = $tool->getName();
            if ($this->projection->has($name)) {
                continue;
            }
            yield self::entry($name, $tool->getSchema());
        }
    }

    /**
     * @param array<string, mixed> $schema the tool's getSchema(): description, inputSchema, annotations
     */
    public static function entry(string $name, array $schema): CapabilityEntry
    {
        $annotations = is_array($schema['annotations'] ?? null) ? $schema['annotations'] : [];
        $title = is_string($annotations['title'] ?? null) && $annotations['title'] !== '' ? $annotations['title'] : $name;
        $inputSchema = is_array($schema['inputSchema'] ?? null) ? $schema['inputSchema'] : [];

        return new CapabilityEntry(
            id: 'mcp/' . $name,
            title: $title,
            description: is_string($schema['description'] ?? null) ? $schema['description'] : '',
            source: CapabilityEntry::SOURCE_MCP,
            surfaces: [ExecutionContext::SURFACE_MCP],
            inputSchema: array_filter($inputSchema, static fn(mixed $key): bool => is_string($key), ARRAY_FILTER_USE_KEY),
            annotations: CapabilityEntry::annotations(
                ($annotations['readOnlyHint'] ?? false) === true,
                ($annotations['destructiveHint'] ?? false) === true,
                ($annotations['idempotentHint'] ?? false) === true,
            ),
            invocations: [ExecutionContext::SURFACE_MCP => $name],
            meta: ['openWorld' => ($annotations['openWorldHint'] ?? false) === true],
        );
    }
}
