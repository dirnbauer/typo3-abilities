<?php

declare(strict_types=1);

namespace Webconsulting\AbilitiesTestListener;

use Webconsulting\Abilities\Catalog\CatalogEntry;
use Webconsulting\Abilities\Catalog\CatalogSourceInterface;

/**
 * Functional test fixture: a catalogue source as a 1.1 extension wired it —
 * tagged by hand with the removed tag name "abilities.capability_source" and
 * with autoconfiguration switched off, so only the old tag is present.
 */
final class LegacyTaggedSource implements CatalogSourceInterface
{
    public function getSource(): string
    {
        return CatalogEntry::SOURCE_REST;
    }

    public function getEntries(): iterable
    {
        yield new CatalogEntry(
            id: 'rest/legacy-tagged',
            title: 'Legacy tagged source',
            description: 'Collected through the deprecated abilities.capability_source tag.',
            source: CatalogEntry::SOURCE_REST,
            surfaces: ['rest'],
            inputSchema: [],
            annotations: CatalogEntry::annotations(readonly: true),
            invocations: ['rest' => 'GET /legacy'],
        );
    }
}
