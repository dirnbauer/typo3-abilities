<?php

declare(strict_types=1);

namespace Webconsulting\Abilities\Catalog;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * A discovery provider of the capability catalogue: one class per origin
 * (abilities, MCP tools, skills, REST endpoints, console commands). Tagged
 * services are collected by the CapabilityCatalog; a source whose optional
 * dependency is not installed simply yields nothing.
 */
#[AutoconfigureTag('abilities.capability_source')]
interface CapabilitySourceInterface
{
    /**
     * The source slug every entry of this provider carries (CapabilityEntry::SOURCE_*).
     */
    public function getSource(): string;

    /**
     * @return iterable<CapabilityEntry>
     */
    public function getCapabilities(): iterable;
}
