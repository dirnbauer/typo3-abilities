<?php

declare(strict_types=1);

namespace Webconsulting\Abilities\Tests\Unit\Catalog;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Webconsulting\Abilities\Catalog\AbilityCatalog;
use Webconsulting\Abilities\Catalog\CatalogEntry;
use Webconsulting\Abilities\Tests\Fixtures\StaticCatalogSource;

final class AbilityCatalogTest extends TestCase
{
    /**
     * @param list<string> $surfaces
     */
    private function entry(string $id, string $source, array $surfaces = ['cli'], string $description = ''): CatalogEntry
    {
        return new CatalogEntry(
            id: $id,
            title: strtoupper($id),
            description: $description,
            source: $source,
            surfaces: $surfaces,
            inputSchema: [],
            annotations: CatalogEntry::annotations(),
            invocations: array_fill_keys($surfaces, 'run ' . $id),
        );
    }

    private function catalog(): AbilityCatalog
    {
        return new AbilityCatalog([
            new StaticCatalogSource('cli', [
                $this->entry('cli/cache:flush', 'cli', ['cli', 'scheduler'], 'Flushes caches.'),
                $this->entry('cli/abilities:run', 'cli'),
            ]),
            new StaticCatalogSource('abilities', [
                $this->entry('content/search', 'abilities', ['mcp', 'rest', 'cli'], 'Searches pages.'),
            ]),
            new StaticCatalogSource('mcp', []),
        ]);
    }

    #[Test]
    public function unitesSourcesSortedByIdAndCountsPerSource(): void
    {
        $catalog = $this->catalog();

        self::assertSame(['abilities', 'cli', 'mcp'], $catalog->sources());
        self::assertSame(
            ['cli/abilities:run', 'cli/cache:flush', 'content/search'],
            array_map(static fn(CatalogEntry $entry): string => $entry->id, $catalog->entries()),
        );

        $array = $catalog->toArray();
        self::assertSame(3, $array['total']);
        self::assertSame(['abilities' => 1, 'cli' => 2, 'mcp' => 0], $array['sources'], 'empty sources are still reported');
        self::assertSame('cli/abilities:run', $array['entries'][0]['id']);
        self::assertInstanceOf(\stdClass::class, $array['entries'][0]['inputSchema'], 'unknown schema is an empty JSON object');
    }

    #[Test]
    public function filtersBySourceSurfaceAndSearch(): void
    {
        $catalog = $this->catalog();

        self::assertSame(['cli/abilities:run', 'cli/cache:flush'], array_map(static fn(CatalogEntry $e): string => $e->id, $catalog->entries('cli')));
        self::assertSame(['cli/cache:flush'], array_map(static fn(CatalogEntry $e): string => $e->id, $catalog->entries(null, 'scheduler')));
        self::assertSame(['content/search'], array_map(static fn(CatalogEntry $e): string => $e->id, $catalog->entries(null, null, 'PAGES')), 'search is case-insensitive over id, title and description');
        self::assertSame([], $catalog->entries('mcp'));
        self::assertSame(['cli' => 1], array_filter($catalog->toArray('cli', 'scheduler')['sources']));
    }

    #[Test]
    public function laterSourcesWinOnDuplicateIds(): void
    {
        $catalog = new AbilityCatalog([
            new StaticCatalogSource('a', [$this->entry('x/y', 'a')]),
            new StaticCatalogSource('b', [$this->entry('x/y', 'b')]),
        ]);

        self::assertCount(1, $catalog->entries());
        self::assertSame('b', $catalog->entries()[0]->source);
    }

    #[Test]
    public function entryToArrayIsTheDocumentedShape(): void
    {
        $entry = new CatalogEntry(
            id: 'mcp/GetPage',
            title: 'Get page',
            description: 'Reads a page.',
            source: CatalogEntry::SOURCE_MCP,
            surfaces: ['mcp'],
            inputSchema: ['type' => 'object'],
            annotations: CatalogEntry::annotations(readonly: true, idempotent: true),
            invocations: ['mcp' => 'GetPage'],
            meta: ['openWorld' => false],
        );

        self::assertSame(
            ['id', 'title', 'description', 'source', 'surfaces', 'inputSchema', 'annotations', 'invocations', 'meta'],
            array_keys($entry->toArray()),
        );
        self::assertSame(['readonly' => true, 'destructive' => false, 'idempotent' => true], $entry->toArray()['annotations']);
        self::assertTrue($entry->matches('reads'));
        self::assertTrue($entry->matches(''));
        self::assertFalse($entry->matches('delete'));
    }
}
