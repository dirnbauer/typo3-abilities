<?php

declare(strict_types=1);

namespace Webconsulting\Abilities\Ability\Content;

use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Type\Bitmask\Permission;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use Webconsulting\Abilities\Ability\Support\BackendUserContext;
use Webconsulting\Abilities\Attribute\AsAbility;
use Webconsulting\Abilities\Domain\ExecutionContext;
use Webconsulting\Abilities\Domain\RiskTier;
use Webconsulting\Abilities\Registry\AbstractAbility;

/**
 * Demo ability: destructive, risk tier high. REST runs it with DELETE; the
 * shipped policy example puts every risk:high ability behind a human review,
 * so REST answers 409 ability_review_required while the CLI
 * (--approve-review) and the backend module (review checkbox) can approve.
 */
#[AsAbility(
    name: 'content/delete-page',
    title: 'Delete page',
    description: 'Soft-deletes a page (sets its deleted flag) together with its content elements through the DataHandler. Pages with subpages are refused unless "recursive" is set.',
    category: 'content',
    scopes: ['pages:write'],
    riskTier: RiskTier::High,
    sideEffects: ['database:write'],
    idempotent: false,
    destructive: true,
    instructions: 'Destructive. Confirm the uid with content/search first and tell the human which page you are about to delete. The default policy requires a review for risk:high abilities: on the CLI pass --approve-review, in the backend module tick the review checkbox; REST cannot approve and answers 409. Deleted pages can be restored from the recycler.',
)]
final class DeletePageAbility extends AbstractAbility
{
    public function __construct(
        private readonly ConnectionPool $connectionPool,
    ) {}

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'required' => ['uid'],
            'additionalProperties' => false,
            'properties' => [
                'uid' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'description' => 'Uid of the page to delete',
                ],
                'recursive' => [
                    'type' => 'boolean',
                    'default' => false,
                    'description' => 'Also delete all subpages; without it a page with subpages is refused',
                ],
            ],
        ];
    }

    public function getOutputSchema(): array
    {
        return [
            'type' => 'object',
            'required' => ['uid', 'deleted', 'subpages'],
            'properties' => [
                'uid' => ['type' => 'integer'],
                'title' => ['type' => 'string'],
                'deleted' => ['type' => 'boolean'],
                'subpages' => ['type' => 'integer', 'description' => 'Number of direct subpages deleted along'],
            ],
        ];
    }

    public function checkPermission(array $input, ExecutionContext $context): bool|string
    {
        $user = BackendUserContext::current();
        if ($user === null) {
            return BackendUserContext::missingUserMessage('content/delete-page');
        }
        if ($user->isAdmin()) {
            return true;
        }

        $uid = is_int($input['uid'] ?? null) ? $input['uid'] : 0;
        $record = $uid > 0 ? BackendUtility::getRecord('pages', $uid) : null;
        if ($record === null) {
            return sprintf('Page #%d does not exist.', $uid);
        }
        if (!$user->doesUserHaveAccess($record, Permission::PAGE_DELETE)) {
            return sprintf('Backend user may not delete page #%d.', $uid);
        }

        return true;
    }

    public function execute(array $input, ExecutionContext $context): mixed
    {
        $user = BackendUserContext::current()
            ?? throw new \RuntimeException(BackendUserContext::missingUserMessage('content/delete-page'), 7480291050);

        $uid = is_int($input['uid'] ?? null) ? $input['uid'] : 0;
        $record = $uid > 0 ? BackendUtility::getRecord('pages', $uid, ['uid', 'title']) : null;
        if ($record === null) {
            throw new \RuntimeException(sprintf('Page #%d does not exist.', $uid), 7480291051);
        }

        $subpages = $this->countSubpages($uid);
        if ($subpages > 0 && ($input['recursive'] ?? false) !== true) {
            throw new \RuntimeException(
                sprintf('Page #%d has %d subpage(s); pass "recursive": true to delete the whole branch.', $uid, $subpages),
                7480291052,
            );
        }

        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start([], ['pages' => [$uid => ['delete' => 1]]], $user);
        $dataHandler->process_cmdmap();

        if ($dataHandler->errorLog !== []) {
            throw new \RuntimeException('DataHandler: ' . implode('; ', $dataHandler->errorLog), 7480291053);
        }

        return [
            'uid' => $uid,
            'title' => (string)$record['title'],
            'deleted' => BackendUtility::getRecord('pages', $uid) === null,
            'subpages' => $subpages,
        ];
    }

    private function countSubpages(int $uid): int
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('pages');
        $queryBuilder->getRestrictions()->removeAll()->add(GeneralUtility::makeInstance(DeletedRestriction::class));

        return (int)$queryBuilder
            ->count('uid')
            ->from('pages')
            ->where($queryBuilder->expr()->eq('pid', $queryBuilder->createNamedParameter($uid, Connection::PARAM_INT)))
            ->executeQuery()
            ->fetchOne();
    }
}
