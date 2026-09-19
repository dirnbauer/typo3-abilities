<?php

declare(strict_types=1);

namespace Webconsulting\Abilities\Tests\Functional\Catalog;

use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;
use Webconsulting\Abilities\Catalog\AbilityCatalog;
use Webconsulting\Abilities\Catalog\CatalogEntry;

/**
 * The skills source against real tables: the fixture extension ships the
 * tx_nrllm_skill and tx_skillflow_skill schemas so neither nr-llm nor
 * skillflow has to be installed. Disabled, hidden and deleted skills stay out.
 */
final class SkillSourceTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = [
        'webconsulting/typo3-abilities',
        __DIR__ . '/../Fixtures/Extensions/abilities_test_skills',
    ];

    #[Test]
    public function cataloguesEnabledSkillsOfBothStores(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/skills.csv');
        $catalog = $this->get(AbilityCatalog::class);
        self::assertInstanceOf(AbilityCatalog::class, $catalog);

        $skills = $catalog->entries(CatalogEntry::SOURCE_SKILLS);
        self::assertSame(
            ['skill/abilities-demo', 'skill/publish-editorial-drafts'],
            array_map(static fn(CatalogEntry $entry): string => $entry->id, $skills),
        );

        $demo = $skills[0];
        self::assertSame('Abilities demo', $demo->title);
        self::assertSame('tx_skillflow_skill', $demo->meta['table']);
        self::assertSame(['mcp__typo3__ability_system_site-info'], $demo->meta['allowedTools']);

        $publish = $skills[1];
        self::assertSame('Reviews pending drafts with a human and publishes them once approved.', $publish->description);
        self::assertSame(['workspace/publish', 'content/search'], $publish->meta['abilities']);
        self::assertSame(['skills'], $publish->surfaces);
        self::assertStringContainsString('workspace/publish, content/search', $publish->invocations['skills']);

        self::assertSame(2, $catalog->toArray(CatalogEntry::SOURCE_SKILLS)['sources']['skills']);
    }
}
