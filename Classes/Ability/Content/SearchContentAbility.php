<?php

declare(strict_types=1);

namespace Webconsulting\Abilities\Ability\Content;

use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\Database\Query\Restriction\WorkspaceRestriction;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Versioning\VersionState;
use Webconsulting\Abilities\Attribute\AsAbility;
use Webconsulting\Abilities\Domain\ExecutionContext;
use Webconsulting\Abilities\Domain\RiskTier;
use Webconsulting\Abilities\Permission\BackendUserContext;
use Webconsulting\Abilities\Registry\AbstractAbility;

/**
 * Demo ability: read-only content search. Shows a GET run with query
 * parameters over REST (?term=...&limit=5), typed input coercion, the
 * workspace-aware QueryBuilder and optional output properties (url).
 */
#[AsAbility(
    name: 'content/search',
    title: 'Search content',
    description: 'Searches pages (title, subtitle, navigation title) and content elements (header, bodytext) for a term and returns the matching records with their page and, where a site is configured, their URL.',
    category: 'content',
    scopes: ['content:read'],
    riskTier: RiskTier::Low,
    sideEffects: [],
    idempotent: true,
    readOnly: true,
    instructions: 'Use a short, distinctive term; matching is a case-insensitive substring match. Narrow with "language" (sys_language_uid) or "tables", raise "limit" only when needed. Results mix pages (table "pages") and content elements (table "tt_content"; "pid" is their page). Hidden records are included and flagged, so drafts are found as well.',
)]
final class SearchContentAbility extends AbstractAbility
{
    public const TABLE_PAGES = 'pages';
    public const TABLE_CONTENT = 'tt_content';

    private const DEFAULT_LIMIT = 20;
    private const MAX_LIMIT = 100;

    public function __construct(
        private readonly ConnectionPool $connectionPool,
        private readonly SiteFinder $siteFinder,
    ) {}

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'required' => ['term'],
            'additionalProperties' => false,
            'properties' => [
                'term' => [
                    'type' => 'string',
                    'minLength' => 2,
                    'maxLength' => 200,
                    'description' => 'Search term (substring, case-insensitive)',
                ],
                'limit' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'maximum' => self::MAX_LIMIT,
                    'default' => self::DEFAULT_LIMIT,
                    'description' => 'Maximum number of results over all tables',
                ],
                'language' => [
                    'type' => ['integer', 'null'],
                    'minimum' => 0,
                    'default' => null,
                    'description' => 'Only records of this sys_language_uid; null = every language',
                ],
                'tables' => [
                    'type' => 'array',
                    'minItems' => 1,
                    'items' => ['type' => 'string', 'enum' => [self::TABLE_PAGES, self::TABLE_CONTENT]],
                    'default' => [self::TABLE_PAGES, self::TABLE_CONTENT],
                    'description' => 'Which tables to search',
                ],
            ],
        ];
    }

    public function getOutputSchema(): array
    {
        return [
            'type' => 'object',
            'required' => ['results', 'total'],
            'properties' => [
                'total' => ['type' => 'integer'],
                'results' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'required' => ['table', 'uid', 'pid', 'title', 'hidden', 'language'],
                        'properties' => [
                            'table' => ['type' => 'string', 'enum' => [self::TABLE_PAGES, self::TABLE_CONTENT]],
                            'uid' => ['type' => 'integer'],
                            'pid' => ['type' => 'integer'],
                            'title' => ['type' => 'string'],
                            'hidden' => ['type' => 'boolean'],
                            'language' => ['type' => 'integer'],
                            'url' => ['type' => 'string'],
                        ],
                    ],
                ],
            ],
        ];
    }

    public function execute(array $input, ExecutionContext $context): mixed
    {
        $term = is_string($input['term'] ?? null) ? trim($input['term']) : '';
        $limit = is_int($input['limit'] ?? null) ? $input['limit'] : self::DEFAULT_LIMIT;
        $language = is_int($input['language'] ?? null) ? $input['language'] : null;
        $tables = is_array($input['tables'] ?? null) ? $input['tables'] : [self::TABLE_PAGES, self::TABLE_CONTENT];
        $user = BackendUserContext::current();
        $workspace = $user === null ? 0 : $user->workspace;

        $results = [];
        if (in_array(self::TABLE_PAGES, $tables, true)) {
            foreach ($this->searchPages($term, $language, $workspace, $limit) as $row) {
                $results[] = $this->present(self::TABLE_PAGES, $row, (string)($row['title'] ?? ''));
            }
        }
        if (in_array(self::TABLE_CONTENT, $tables, true) && count($results) < $limit) {
            foreach ($this->searchContent($term, $language, $workspace, $limit - count($results)) as $row) {
                $header = trim((string)($row['header'] ?? ''));
                $results[] = $this->present(
                    self::TABLE_CONTENT,
                    $row,
                    $header !== '' ? $header : sprintf('Content element #%d', (int)$row['uid']),
                );
            }
        }

        return ['results' => $results, 'total' => count($results)];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function searchPages(string $term, ?int $language, int $workspace, int $limit): array
    {
        $queryBuilder = $this->queryBuilder(self::TABLE_PAGES, $workspace);
        $like = $this->like($queryBuilder, $term);
        $queryBuilder
            ->select('uid', 'pid', 'title', 'hidden', 'sys_language_uid', 't3ver_oid', 't3ver_state')
            ->from(self::TABLE_PAGES)
            ->where(
                $queryBuilder->expr()->or(
                    $queryBuilder->expr()->like('title', $like),
                    $queryBuilder->expr()->like('subtitle', $like),
                    $queryBuilder->expr()->like('nav_title', $like),
                ),
            );

        return $this->fetch($queryBuilder, $language, $limit);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function searchContent(string $term, ?int $language, int $workspace, int $limit): array
    {
        $queryBuilder = $this->queryBuilder(self::TABLE_CONTENT, $workspace);
        $like = $this->like($queryBuilder, $term);
        $queryBuilder
            ->select('uid', 'pid', 'header', 'hidden', 'sys_language_uid', 't3ver_oid', 't3ver_state')
            ->from(self::TABLE_CONTENT)
            ->where(
                $queryBuilder->expr()->or(
                    $queryBuilder->expr()->like('header', $like),
                    $queryBuilder->expr()->like('bodytext', $like),
                ),
            );

        return $this->fetch($queryBuilder, $language, $limit);
    }

    private function queryBuilder(string $table, int $workspace): QueryBuilder
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable($table);
        $queryBuilder->getRestrictions()
            ->removeAll()
            ->add(GeneralUtility::makeInstance(DeletedRestriction::class))
            ->add(GeneralUtility::makeInstance(WorkspaceRestriction::class, $workspace));

        return $queryBuilder;
    }

    private function like(QueryBuilder $queryBuilder, string $term): string
    {
        return $queryBuilder->createNamedParameter('%' . $queryBuilder->escapeLikeWildcards($term) . '%');
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fetch(QueryBuilder $queryBuilder, ?int $language, int $limit): array
    {
        if ($language !== null) {
            $queryBuilder->andWhere(
                $queryBuilder->expr()->eq('sys_language_uid', $queryBuilder->createNamedParameter($language, Connection::PARAM_INT)),
            );
        }
        $rows = $queryBuilder
            ->orderBy('pid')
            ->addOrderBy('uid')
            ->setMaxResults($limit)
            ->executeQuery()
            ->fetchAllAssociative();

        // Delete placeholders are versions scheduled for deletion: not a hit.
        return array_values(array_filter(
            $rows,
            static fn(array $row): bool => (int)($row['t3ver_state'] ?? 0) !== VersionState::DELETE_PLACEHOLDER->value,
        ));
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function present(string $table, array $row, string $title): array
    {
        $versionOf = (int)($row['t3ver_oid'] ?? 0);
        $uid = $versionOf > 0 ? $versionOf : (int)$row['uid'];
        $pid = (int)$row['pid'];
        $language = (int)($row['sys_language_uid'] ?? 0);

        $result = [
            'table' => $table,
            'uid' => $uid,
            'pid' => $pid,
            'title' => $title,
            'hidden' => (bool)($row['hidden'] ?? false),
            'language' => $language,
        ];

        $pageUid = $table === self::TABLE_PAGES ? $uid : $pid;
        $url = $result['hidden'] === true
            ? null
            : $this->url($pageUid, $language, $table === self::TABLE_CONTENT ? 'c' . $uid : '');
        if ($url !== null) {
            $result['url'] = $url;
        }

        return $result;
    }

    /**
     * The frontend URL of a page, or null when there is none to give.
     *
     * Hidden records never get one: TYPO3's page router cannot route a page
     * it does not consider visible and falls back to the site base, and a URL
     * silently pointing at the wrong page is worse for an agent than no URL.
     */
    private function url(int $pageUid, int $languageId, string $fragment): ?string
    {
        if (!$this->isRoutablePage($pageUid)) {
            return null;
        }

        try {
            $site = $this->siteFinder->getSiteByPageId($pageUid);
            $uri = $site->getRouter()->generateUri($pageUid, ['_language' => $site->getLanguageById($languageId)], $fragment);
        } catch (\Throwable) {
            // No site for this page tree, or an unknown language: the URL is optional.
            return null;
        }

        return (string)$uri;
    }

    private function isRoutablePage(int $pageUid): bool
    {
        $page = BackendUtility::getRecord(self::TABLE_PAGES, $pageUid, ['hidden']);

        return $page !== null && (int)($page['hidden'] ?? 0) === 0;
    }
}
