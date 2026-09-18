<?php

declare(strict_types=1);

namespace Webconsulting\Abilities\Tests\Fixtures;

use Webconsulting\Abilities\Catalog\CapabilityEntry;
use Webconsulting\Abilities\Catalog\CapabilitySourceInterface;

/** A catalogue source with a fixed list of entries. */
final class StaticCapabilitySource implements CapabilitySourceInterface
{
    /**
     * @param list<CapabilityEntry> $entries
     */
    public function __construct(
        private readonly string $source,
        private readonly array $entries,
    ) {}

    public function getSource(): string
    {
        return $this->source;
    }

    public function getCapabilities(): iterable
    {
        return $this->entries;
    }
}
