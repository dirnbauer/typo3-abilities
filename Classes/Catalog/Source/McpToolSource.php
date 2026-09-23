<?php

declare(strict_types=1);

namespace Webconsulting\Abilities\Catalog\Source;

use Hn\McpServer\MCP\ToolRegistry;
use Webconsulting\Abilities\Catalog\CatalogEntry;
use Webconsulting\Abilities\Catalog\CatalogSourceInterface;
use Webconsulting\Abilities\Domain\ExecutionContext;
use Webconsulting\Abilities\Projection\Mcp\McpProjection;

/**
 * Every native tool of hn/typo3-mcp-server, read from its ToolRegistry with
 * the tool's own schema and annotations. Abilities the server projects as
 * tools (ability_*) are skipped — they are catalogued as abilities. The
 * server extension is optional: without it this source yields nothing.
 */
final readonly class McpToolSource implements CatalogSourceInterface
{
    public function __construct(
        private McpProjection $projection,
        private ?ToolRegistry $toolRegistry = null,
    ) {}

    public function getSource(): string
    {
        return CatalogEntry::SOURCE_MCP;
    }

    public function getEntries(): iterable
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
    public static function entry(string $name, array $schema): CatalogEntry
    {
        $annotations = is_array($schema['annotations'] ?? null) ? $schema['annotations'] : [];
        $title = is_string($annotations['title'] ?? null) && $annotations['title'] !== '' ? $annotations['title'] : $name;
        $inputSchema = is_array($schema['inputSchema'] ?? null) ? $schema['inputSchema'] : [];

        return new CatalogEntry(
            id: 'mcp/' . $name,
            title: $title,
            description: is_string($schema['description'] ?? null) ? $schema['description'] : '',
            source: CatalogEntry::SOURCE_MCP,
            surfaces: [ExecutionContext::SURFACE_MCP],
            inputSchema: array_filter($inputSchema, is_string(...), ARRAY_FILTER_USE_KEY),
            annotations: CatalogEntry::annotations(
                ($annotations['readOnlyHint'] ?? false) === true,
                ($annotations['destructiveHint'] ?? false) === true,
                ($annotations['idempotentHint'] ?? false) === true,
            ),
            invocations: [ExecutionContext::SURFACE_MCP => $name],
            meta: ['openWorld' => ($annotations['openWorldHint'] ?? false) === true],
        );
    }
}
