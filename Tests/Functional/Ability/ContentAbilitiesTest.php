<?php

declare(strict_types=1);

namespace Webconsulting\Abilities\Tests\Functional\Ability;

use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Configuration\SiteWriter;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;
use Webconsulting\Abilities\Domain\AbilityResult;
use Webconsulting\Abilities\Domain\ExecutionContext;
use Webconsulting\Abilities\Execution\AbilityExecutor;
use Webconsulting\Abilities\Registry\AbilitiesRegistry;
use Webconsulting\Abilities\Tests\Support\TypeNarrowing;
use Webconsulting\AbilitiesTestListener\DraftTitleListener;

/**
 * The content demo abilities against a real TYPO3 instance: workspace-aware
 * search with site URLs, DataHandler-backed draft creation with PSR-14
 * listeners on the Before/After events (the abilities_test_listener fixture
 * extension), and review-gated destructive deletion with traces.
 */
final class ContentAbilitiesTest extends FunctionalTestCase
{
    use TypeNarrowing;

    protected array $testExtensionsToLoad = [
        'webconsulting/typo3-abilities',
        __DIR__ . '/../Fixtures/Extensions/abilities_test_listener',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/be_users.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/be_groups.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/pages.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/tt_content.csv');

        $siteWriter = $this->get(SiteWriter::class);
        self::assertInstanceOf(SiteWriter::class, $siteWriter);
        $siteWriter->createNewBasicSite('main', 1, 'https://example.test/');
        $cacheManager = $this->get(CacheManager::class);
        self::assertInstanceOf(CacheManager::class, $cacheManager);
        $cacheManager->getCache('core')->remove('sites-configuration');

        GeneralUtility::mkdir_deep(Environment::getProjectPath() . '/config');
        copy(__DIR__ . '/../../../Resources/Private/Examples/abilities-policy.yaml', Environment::getProjectPath() . '/config/abilities-policy.yaml');

        $backendUser = $this->setUpBackendUser(1);
        $languageServiceFactory = $this->get(LanguageServiceFactory::class);
        self::assertInstanceOf(LanguageServiceFactory::class, $languageServiceFactory);
        $GLOBALS['LANG'] = $languageServiceFactory->createFromUserPreferences($backendUser);
        DraftTitleListener::$seen = [];
    }

    /**
     * @param array<string, mixed> $input
     */
    private function runAbility(string $ability, array $input, ?ExecutionContext $context = null): AbilityResult
    {
        $registry = $this->get(AbilitiesRegistry::class);
        $executor = $this->get(AbilityExecutor::class);
        self::assertInstanceOf(AbilitiesRegistry::class, $registry);
        self::assertInstanceOf(AbilityExecutor::class, $executor);

        return $executor->execute($registry->get($ability), $input, $context ?? ExecutionContext::cli(), $registry->getDefinition($ability));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function results(AbilityResult $result): array
    {
        self::assertTrue($result->ok, (string)$result->error);
        $data = self::asArray($result->data);
        $results = array_map(static fn(mixed $row): array => self::asArray($row), array_values(self::asArray($data['results'])));
        self::assertSame(count($results), $data['total']);

        return $results;
    }

    #[Test]
    public function searchFindsPagesAndContentIncludingHiddenButNotDeleted(): void
    {
        $results = $this->results($this->runAbility('content/search', ['term' => 'roadmap']));

        self::assertCount(2, $results);
        self::assertSame(
            ['pages', 3, 1, 'Secret roadmap', true, 0],
            array_values($results[0]),
            'a hidden page is found and flagged, but has no routable URL',
        );
        self::assertSame('tt_content', $results[1]['table']);
        self::assertSame(2, $results[1]['uid']);
        self::assertSame(4, $results[1]['pid']);
        self::assertSame('Content element #2', $results[1]['title'], 'content without header gets a synthetic title');
        self::assertSame('https://example.test/about/team#c2', $results[1]['url']);
    }

    #[Test]
    public function searchMatchesNavigationTitleSubtitleAndHonoursFilters(): void
    {
        $byNavTitle = $this->results($this->runAbility('content/search', ['term' => 'about']));
        self::assertSame([['pages', 2]], array_map(static fn(array $row): array => [$row['table'], $row['uid']], $byNavTitle));

        $bySubtitle = $this->results($this->runAbility('content/search', ['term' => 'who we are', 'tables' => ['pages']]));
        self::assertSame(2, $bySubtitle[0]['uid']);

        $german = $this->results($this->runAbility('content/search', ['term' => 'uns', 'language' => 1]));
        self::assertSame([['tt_content', 3, 1]], array_map(static fn(array $row): array => [$row['table'], $row['uid'], $row['language']], $german));

        // "roadmap" matches a page and a content element; the limit spans both tables.
        $limited = $this->results($this->runAbility('content/search', ['term' => 'roadmap', 'limit' => 1]));
        self::assertSame([['pages', 3]], array_map(static fn(array $row): array => [$row['table'], $row['uid']], $limited));

        $invalid = $this->runAbility('content/search', ['term' => 'x']);
        self::assertSame(AbilityResult::ERROR_INVALID_INPUT, $invalid->errorCode);
    }

    #[Test]
    public function createPageDraftRunsThroughDataHandlerAndTheListenersRewriteAndObserve(): void
    {
        $result = $this->runAbility('content/create-page-draft', ['parent' => 2, 'title' => 'Press']);

        self::assertTrue($result->ok, (string)$result->error);
        $data = self::asArray($result->data);
        $uid = $data['uid'];
        self::assertIsInt($uid);
        self::assertSame(2, $data['pid']);
        self::assertTrue($data['hidden']);
        self::assertSame(0, $data['workspace']);
        self::assertSame('/about/draft-press', $data['slug'], 'slug is generated from the (listener-prefixed) title below the parent');

        $record = BackendUtility::getRecord('pages', $uid);
        self::assertIsArray($record);
        self::assertSame(DraftTitleListener::PREFIX . 'Press', $record['title'], 'BeforeAbilityExecutionEvent rewrote the input');
        self::assertSame(1, (int)$record['hidden']);

        self::assertCount(1, DraftTitleListener::$seen);
        self::assertSame('content/create-page-draft', DraftTitleListener::$seen[0]['ability']);
        self::assertTrue(DraftTitleListener::$seen[0]['ok']);
        self::assertSame('cli', DraftTitleListener::$seen[0]['surface']);
        self::assertSame('Press', DraftTitleListener::$seen[0]['input']['title'], 'the After event carries the raw caller input');

        $vetoed = $this->runAbility('content/create-page-draft', ['parent' => 2, 'title' => 'veto']);
        self::assertSame(AbilityResult::ERROR_POLICY_DENIED, $vetoed->errorCode);
        self::assertStringContainsString('not allowed on this site', (string)$vetoed->error);

        $afterVeto = DraftTitleListener::$seen;
        self::assertCount(2, $afterVeto);
        self::assertFalse($afterVeto[1]['ok'], 'denials reach the After listener too');

        $missingParent = $this->runAbility('content/create-page-draft', ['parent' => 999, 'title' => 'Orphan']);
        self::assertSame(AbilityResult::ERROR_EXECUTION_ERROR, $missingParent->errorCode);
    }

    #[Test]
    public function deletePageIsReviewGatedGuardsSubpagesAndLeavesTraces(): void
    {
        $unapproved = $this->runAbility('content/delete-page', ['uid' => 4]);
        self::assertSame(AbilityResult::ERROR_REVIEW_REQUIRED, $unapproved->errorCode);
        self::assertIsArray(BackendUtility::getRecord('pages', 4), 'nothing happened without approval');

        $approved = ExecutionContext::cli(reviewApproved: true);

        $branch = $this->runAbility('content/delete-page', ['uid' => 2], $approved);
        self::assertSame(AbilityResult::ERROR_EXECUTION_ERROR, $branch->errorCode);
        self::assertStringContainsString('1 subpage(s)', (string)$branch->error);
        self::assertIsArray(BackendUtility::getRecord('pages', 2));

        $leaf = $this->runAbility('content/delete-page', ['uid' => 4], $approved);
        self::assertTrue($leaf->ok, (string)$leaf->error);
        self::assertSame(['uid' => 4, 'title' => 'Team', 'deleted' => true, 'subpages' => 0], $leaf->data);
        self::assertNull(BackendUtility::getRecord('pages', 4));
        self::assertNull(BackendUtility::getRecord('tt_content', 2), 'content on the page is deleted along');

        $recursive = $this->runAbility('content/delete-page', ['uid' => 2, 'recursive' => true], $approved);
        self::assertTrue($recursive->ok, (string)$recursive->error);
        self::assertNull(BackendUtility::getRecord('pages', 2));

        $traces = $this->getConnectionPool()->getConnectionForTable('tx_abilities_trace')
            ->select(['ok', 'error_code', 'surface'], 'tx_abilities_trace', ['ability' => 'content/delete-page'], [], ['uid' => 'ASC'])
            ->fetchAllAssociative();
        self::assertSame(
            [[0, 'ability_review_required'], [0, 'ability_cannot_execute'], [1, ''], [1, '']],
            array_map(static fn(array $row): array => [(int)$row['ok'], $row['error_code']], $traces),
        );
        self::assertSame(['cli'], array_values(array_unique(array_column($traces, 'surface'))));
    }
}
