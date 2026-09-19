<?php

declare(strict_types=1);

namespace Webconsulting\Abilities\Tests\Unit\Compatibility;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Webconsulting\Abilities\Catalog\AbilityCatalog;
use Webconsulting\Abilities\Catalog\CatalogEntry;
use Webconsulting\Abilities\Catalog\CatalogSourceInterface;

/**
 * The 1.1 names of the catalogue layer, kept as aliases by
 * Classes/Compatibility/ClassAliases.php (loaded through composer
 * "autoload.files"). One test per alias so that deleting them in 2.0.0 is a
 * deliberate act. \ReflectionClass::getName() reports the canonical name, so
 * each test proves the old name resolves to the new class rather than to a
 * leftover copy.
 *
 * @deprecated since 1.2.0, removed in 2.0.0
 */
final class DeprecatedClassAliasesTest extends TestCase
{
    #[Test]
    public function capabilityCatalogIsTheAbilityCatalog(): void
    {
        self::assertTrue(class_exists('Webconsulting\Abilities\Catalog\CapabilityCatalog'));
        self::assertSame(
            AbilityCatalog::class,
            (new \ReflectionClass('Webconsulting\Abilities\Catalog\CapabilityCatalog'))->getName(),
        );
    }

    #[Test]
    public function capabilityEntryIsTheCatalogEntry(): void
    {
        self::assertTrue(class_exists('Webconsulting\Abilities\Catalog\CapabilityEntry'));
        self::assertSame(
            CatalogEntry::class,
            (new \ReflectionClass('Webconsulting\Abilities\Catalog\CapabilityEntry'))->getName(),
        );
    }

    #[Test]
    public function capabilitySourceInterfaceIsTheCatalogSourceInterface(): void
    {
        self::assertTrue(interface_exists('Webconsulting\Abilities\Catalog\CapabilitySourceInterface'));
        self::assertSame(
            CatalogSourceInterface::class,
            (new \ReflectionClass('Webconsulting\Abilities\Catalog\CapabilitySourceInterface'))->getName(),
        );
    }
}
