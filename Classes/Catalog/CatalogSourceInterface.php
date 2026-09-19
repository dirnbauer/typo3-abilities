<?php

declare(strict_types=1);

namespace Webconsulting\Abilities\Catalog;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * A discovery provider of catalogue entries: one class per origin (this
 * registry's abilities, MCP tools, skills, REST endpoints, console commands).
 * Tagged services are collected by the AbilityCatalog; a source whose optional
 * dependency is not installed simply yields nothing.
 */
#[AutoconfigureTag('abilities.catalog_source')]
interface CatalogSourceInterface
{
    /**
     * The source slug every entry of this provider carries (CatalogEntry::SOURCE_*).
     */
    public function getSource(): string;

    /**
     * @return iterable<CatalogEntry>
     */
    public function getEntries(): iterable;
}
