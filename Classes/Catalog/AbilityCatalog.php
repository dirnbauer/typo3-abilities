<?php

declare(strict_types=1);

namespace Webconsulting\Abilities\Catalog;

use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * The unified, discoverable catalogue of every ability this installation can
 * perform: the union of every CatalogSourceInterface, sorted by id, filterable
 * by source, surface and free text. Projected onto CLI (abilities:catalog),
 * REST ({base}/catalog), MCP (ability_abilities_catalog) and the backend
 * module so an agent can discover, then use.
 *
 * Entries are collected once per request; sources may query the database.
 */
final class AbilityCatalog
{
    /** @var list<CatalogEntry>|null */
    private ?array $entries = null;

    /**
     * @param iterable<CatalogSourceInterface> $sources
     */
    public function __construct(
        #[AutowireIterator('abilities.catalog_source')]
        private readonly iterable $sources,
    ) {}

    /**
     * @return list<string> registered source slugs, sorted
     */
    public function sources(): array
    {
        $slugs = [];
        foreach ($this->sources as $source) {
            $slugs[$source->getSource()] = true;
        }
        ksort($slugs);

        return array_keys($slugs);
    }

    /**
     * @return list<CatalogEntry> sorted by id
     */
    public function entries(?string $source = null, ?string $surface = null, string $search = ''): array
    {
        return array_values(array_filter(
            $this->all(),
            static fn(CatalogEntry $entry): bool
                => ($source === null || $entry->source === $source)
                && ($surface === null || in_array($surface, $entry->surfaces, true))
                && $entry->matches($search),
        ));
    }

    /**
     * The catalogue as every surface publishes it.
     *
     * @return array{entries: list<array<string, mixed>>, total: int, sources: array<string, int>}
     */
    public function toArray(?string $source = null, ?string $surface = null, string $search = ''): array
    {
        $entries = $this->entries($source, $surface, $search);
        $counts = array_fill_keys($this->sources(), 0);
        foreach ($entries as $entry) {
            $counts[$entry->source] = ($counts[$entry->source] ?? 0) + 1;
        }

        return [
            'entries' => array_map(static fn(CatalogEntry $entry): array => $entry->toArray(), $entries),
            'total' => count($entries),
            'sources' => $counts,
        ];
    }

    /**
     * @return list<CatalogEntry>
     */
    private function all(): array
    {
        if ($this->entries !== null) {
            return $this->entries;
        }

        $entries = [];
        foreach ($this->sources as $source) {
            foreach ($source->getEntries() as $entry) {
                $entries[$entry->id] = $entry;
            }
        }
        ksort($entries, SORT_STRING);

        return $this->entries = array_values($entries);
    }
}
