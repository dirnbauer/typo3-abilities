<?php

declare(strict_types=1);

namespace Webconsulting\Abilities\Tests\Functional\Ability;

use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;
use Webconsulting\Abilities\Domain\AbilityResult;
use Webconsulting\Abilities\Domain\ExecutionContext;
use Webconsulting\Abilities\Execution\AbilityExecutor;
use Webconsulting\Abilities\Registry\AbilitiesRegistry;
use Webconsulting\Abilities\Tests\Support\TypeNarrowing;

/**
 * workspace/publish end to end with EXT:workspaces: a draft created in a
 * workspace by content/create-page-draft, listed by the dry run, published
 * by the real run, and a no-op on the idempotent repeat — every attempt
 * (including the review denial) traced.
 */
final class WorkspacePublishAbilityTest extends FunctionalTestCase
{
    use TypeNarrowing;

    protected array $coreExtensionsToLoad = ['workspaces'];

    protected array $testExtensionsToLoad = ['webconsulting/typo3-abilities'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/be_users.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/be_groups.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/pages.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/sys_workspace.csv');

        GeneralUtility::mkdir_deep(Environment::getProjectPath() . '/config');
        copy(__DIR__ . '/../../../Resources/Private/Examples/abilities-policy.yaml', Environment::getProjectPath() . '/config/abilities-policy.yaml');

        $backendUser = $this->setUpBackendUser(1);
        $backendUser->setTemporaryWorkspace(1);
        $languageServiceFactory = $this->get(LanguageServiceFactory::class);
        self::assertInstanceOf(LanguageServiceFactory::class, $languageServiceFactory);
        $GLOBALS['LANG'] = $languageServiceFactory->createFromUserPreferences($backendUser);
    }

    /**
     * @param array<string, mixed> $input
     */
    private function runAbility(string $ability, array $input, bool $approved = true): AbilityResult
    {
        $registry = $this->get(AbilitiesRegistry::class);
        $executor = $this->get(AbilityExecutor::class);
        self::assertInstanceOf(AbilitiesRegistry::class, $registry);
        self::assertInstanceOf(AbilityExecutor::class, $executor);

        return $executor->execute(
            $registry->get($ability),
            $input,
            ExecutionContext::cli(reviewApproved: $approved),
            $registry->getDefinition($ability),
        );
    }

    #[Test]
    public function dryRunListsPublishMovesLiveAndRepeatIsANoOp(): void
    {
        $draft = $this->runAbility('content/create-page-draft', ['parent' => 2, 'title' => 'Launch']);
        self::assertTrue($draft->ok, (string)$draft->error);
        $draftData = self::asArray($draft->data);
        self::assertSame(1, $draftData['workspace'], 'created in the backend user\'s workspace');
        $uid = $draftData['uid'];
        self::assertIsInt($uid);

        $denied = $this->runAbility('workspace/publish', ['workspace' => 1], approved: false);
        self::assertSame(AbilityResult::ERROR_REVIEW_REQUIRED, $denied->errorCode, 'risk:high needs a review per the shipped policy example');

        $dryRun = $this->runAbility('workspace/publish', ['workspace' => 1]);
        self::assertTrue($dryRun->ok, (string)$dryRun->error);
        $listed = self::asArray($dryRun->data);
        self::assertSame(['workspace' => 1, 'title' => 'Editorial drafts', 'dryRun' => true, 'pending' => 1, 'published' => 0], array_slice($listed, 0, 5));
        $record = self::asArray(self::asArray($listed['records'])[0]);
        self::assertSame(['pages', $uid, $uid, 2, 'Launch', 0], array_values($record));
        $stillDraft = BackendUtility::getRecord('pages', $uid);
        self::assertIsArray($stillDraft);
        self::assertSame(1, (int)$stillDraft['t3ver_wsid'], 'a dry run publishes nothing');

        $publish = $this->runAbility('workspace/publish', ['workspace' => 1, 'dryRun' => false]);
        self::assertTrue($publish->ok, (string)$publish->error);
        $published = self::asArray($publish->data);
        self::assertSame(1, $published['pending']);
        self::assertSame(1, $published['published']);
        $live = BackendUtility::getRecord('pages', $uid);
        self::assertIsArray($live);
        self::assertSame(0, (int)$live['t3ver_wsid'], 'the new page is live now');
        self::assertSame('Launch', $live['title']);
        self::assertSame(1, (int)$live['hidden'], 'publishing keeps the draft hidden');

        $again = $this->runAbility('workspace/publish', ['workspace' => 1, 'dryRun' => false]);
        self::assertTrue($again->ok, (string)$again->error);
        self::assertSame(['pending' => 0, 'published' => 0, 'records' => []], array_slice(self::asArray($again->data), 3));

        $unknown = $this->runAbility('workspace/publish', ['workspace' => 99]);
        self::assertSame(AbilityResult::ERROR_EXECUTION_ERROR, $unknown->errorCode);
        self::assertStringContainsString('does not exist', (string)$unknown->error);

        $traces = $this->getConnectionPool()->getConnectionForTable('tx_abilities_trace')
            ->select(['ok', 'error_code', 'input'], 'tx_abilities_trace', ['ability' => 'workspace/publish'], [], ['uid' => 'ASC'])
            ->fetchAllAssociative();
        self::assertSame(
            [
                [0, 'ability_review_required', '{"workspace":1}'],
                [1, '', '{"workspace":1}'],
                [1, '', '{"workspace":1,"dryRun":false}'],
                [1, '', '{"workspace":1,"dryRun":false}'],
                [0, 'ability_cannot_execute', '{"workspace":99}'],
            ],
            array_map(static fn(array $row): array => [(int)$row['ok'], $row['error_code'], $row['input']], $traces),
        );
    }
}
