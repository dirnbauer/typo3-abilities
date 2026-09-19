<?php

declare(strict_types=1);

namespace Webconsulting\Abilities\Tests\Fixtures;

use Webconsulting\Abilities\Catalog\CatalogEntry;
use Webconsulting\Abilities\Catalog\CatalogSourceInterface;

/** A catalogue source with a fixed list of entries. */
final class StaticCatalogSource implements CatalogSourceInterface
{
    /**
     * @param list<CatalogEntry> $entries
     */
    public function __construct(
        private readonly string $source,
        private readonly array $entries,
    ) {}

    public function getSource(): string
    {
        return $this->source;
    }

    public function getEntries(): iterable
    {
        return $this->entries;
    }
}
