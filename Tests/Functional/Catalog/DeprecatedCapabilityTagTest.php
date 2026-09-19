<?php

declare(strict_types=1);

namespace Webconsulting\Abilities\Tests\Functional\Catalog;

use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;
use Webconsulting\Abilities\Catalog\AbilityCatalog;
use Webconsulting\Abilities\Catalog\CatalogEntry;

/**
 * Backwards compatibility for the 1.1 DI tag name: a source still tagged
 * "abilities.capability_source" is promoted to "abilities.catalog_source" by
 * the compiler pass in Configuration/Services.php and ends up in the
 * catalogue. Delete this test together with the pass in 2.0.0.
 *
 * @deprecated since 1.2.0, removed in 2.0.0
 */
final class DeprecatedCapabilityTagTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = [
        'webconsulting/typo3-abilities',
        __DIR__ . '/../Fixtures/Extensions/abilities_test_listener',
    ];

    #[Test]
    public function aSourceTaggedWithTheRemovedTagNameIsStillCollected(): void
    {
        $catalog = $this->get(AbilityCatalog::class);
        self::assertInstanceOf(AbilityCatalog::class, $catalog);

        $ids = array_map(
            static fn(CatalogEntry $entry): string => $entry->id,
            $catalog->entries(CatalogEntry::SOURCE_REST),
        );
        self::assertContains('rest/legacy-tagged', $ids);
    }
}
