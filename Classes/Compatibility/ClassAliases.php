<?php

declare(strict_types=1);

use Webconsulting\Abilities\Catalog\AbilityCatalog;
use Webconsulting\Abilities\Catalog\CatalogEntry;
use Webconsulting\Abilities\Catalog\CatalogSourceInterface;

/**
 * Until 1.1.0 the catalogue layer was named with the noun "capability". In
 * this extension "capability" is reserved for the MCP capability manifest's
 * permission gating, so the discovery layer now speaks of abilities and
 * catalogue entries. These aliases keep 1.1 code loading; they are loaded via
 * composer "autoload.files".
 *
 * @deprecated since 1.2.0, removed in 2.0.0 — use the new names.
 */
class_alias(AbilityCatalog::class, 'Webconsulting\\Abilities\\Catalog\\CapabilityCatalog');
class_alias(CatalogEntry::class, 'Webconsulting\\Abilities\\Catalog\\CapabilityEntry');
class_alias(CatalogSourceInterface::class, 'Webconsulting\\Abilities\\Catalog\\CapabilitySourceInterface');
