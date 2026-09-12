<?php

declare(strict_types=1);

namespace Webconsulting\Abilities\Security;

use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;

final class DatabaseTokenStorage implements TokenStorageInterface
{
    public const TABLE = 'tx_abilities_token';

    public function __construct(
        private readonly ConnectionPool $connectionPool,
    ) {}

    public function findByHash(string $tokenHash): ?array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $row = $queryBuilder
            ->select('*')
            ->from(self::TABLE)
            ->where(
                $queryBuilder->expr()->eq('token_hash', $queryBuilder->createNamedParameter($tokenHash)),
                $queryBuilder->expr()->eq('deleted', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
            )
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();

        return is_array($row) ? $row : null;
    }

    public function insert(array $fields): int
    {
        $connection = $this->connectionPool->getConnectionForTable(self::TABLE);
        $connection->insert(self::TABLE, $fields);

        return (int)$connection->lastInsertId();
    }

    public function findAll(): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);

        return $queryBuilder
            ->select('*')
            ->from(self::TABLE)
            ->where($queryBuilder->expr()->eq('deleted', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)))
            ->orderBy('uid', 'DESC')
            ->executeQuery()
            ->fetchAllAssociative();
    }

    public function revoke(int $uid): bool
    {
        $affected = $this->connectionPool->getConnectionForTable(self::TABLE)->update(
            self::TABLE,
            ['deleted' => 1, 'tstamp' => time()],
            ['uid' => $uid, 'deleted' => 0],
        );

        return $affected > 0;
    }

    public function touch(int $uid, int $timestamp): void
    {
        $this->connectionPool->getConnectionForTable(self::TABLE)->update(
            self::TABLE,
            ['last_used' => $timestamp],
            ['uid' => $uid],
        );
    }
}
