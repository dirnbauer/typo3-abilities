<?php

declare(strict_types=1);

namespace Webconsulting\Abilities\Trace;

use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;

/**
 * Read access to the execution traces (tx_abilities_trace) for the backend
 * module's Traces tab: the newest rows, optionally filtered by ability,
 * surface or outcome.
 *
 * Traces are an append-only observation log written by the TraceRecorder;
 * nothing here writes.
 */
final class TraceRepository
{
    public const DEFAULT_LIMIT = 50;
    public const MAX_LIMIT = 200;

    public function __construct(
        private readonly ConnectionPool $connectionPool,
    ) {}

    /**
     * @return list<array{uid: int, crdate: int, ability: string, surface: string, ok: bool, errorCode: string, error: string, input: string, durationMs: int, beUser: int}>
     */
    public function findLatest(
        ?string $ability = null,
        ?string $surface = null,
        ?bool $ok = null,
        int $limit = self::DEFAULT_LIMIT,
    ): array {
        $queryBuilder = $this->queryBuilder();
        $queryBuilder
            ->select('*')
            ->from(TraceRecorder::TABLE)
            ->orderBy('uid', 'DESC')
            ->setMaxResults(max(1, min(self::MAX_LIMIT, $limit)));

        if ($ability !== null && $ability !== '') {
            $queryBuilder->andWhere(
                $queryBuilder->expr()->eq('ability', $queryBuilder->createNamedParameter($ability)),
            );
        }
        if ($surface !== null && $surface !== '') {
            $queryBuilder->andWhere(
                $queryBuilder->expr()->eq('surface', $queryBuilder->createNamedParameter($surface)),
            );
        }
        if ($ok !== null) {
            $queryBuilder->andWhere(
                $queryBuilder->expr()->eq('ok', $queryBuilder->createNamedParameter($ok ? 1 : 0, Connection::PARAM_INT)),
            );
        }

        return array_map(
            static fn(array $row): array => [
                'uid' => (int)$row['uid'],
                'crdate' => (int)$row['crdate'],
                'ability' => (string)$row['ability'],
                'surface' => (string)$row['surface'],
                'ok' => (bool)$row['ok'],
                'errorCode' => (string)$row['error_code'],
                'error' => (string)$row['error'],
                'input' => (string)$row['input'],
                'durationMs' => (int)$row['duration_ms'],
                'beUser' => (int)$row['be_user'],
            ],
            $queryBuilder->executeQuery()->fetchAllAssociative(),
        );
    }

    /**
     * Distinct surfaces that actually occur in the trace log — the Traces
     * tab offers exactly those as filter options.
     *
     * @return list<string>
     */
    public function surfacesInUse(): array
    {
        $rows = $this->queryBuilder()
            ->selectLiteral('DISTINCT ' . $this->queryBuilder()->quoteIdentifier('surface'))
            ->from(TraceRecorder::TABLE)
            ->orderBy('surface')
            ->executeQuery()
            ->fetchAllAssociative();

        return array_values(array_filter(
            array_map(static fn(array $row): string => (string)$row['surface'], $rows),
            static fn(string $surface): bool => $surface !== '',
        ));
    }

    public function countAll(): int
    {
        return (int)$this->queryBuilder()
            ->count('uid')
            ->from(TraceRecorder::TABLE)
            ->executeQuery()
            ->fetchOne();
    }

    private function queryBuilder(): QueryBuilder
    {
        // tx_abilities_trace has no enable columns and no soft delete; the
        // default restrictions would look for fields that do not exist.
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(TraceRecorder::TABLE);
        $queryBuilder->getRestrictions()->removeAll();

        return $queryBuilder;
    }
}
