<?php

declare(strict_types=1);

namespace Webconsulting\Abilities\Permission;

use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use Webconsulting\Abilities\Domain\ExecutionContext;

/**
 * Resolves the ability scopes a TYPO3 backend user holds: the union of the
 * be_groups.tx_abilities_scopes lists of all groups (subgroups included) the
 * user belongs to. Administrators hold every scope ("*").
 *
 * This is the permission bridge between TYPO3's group model and the
 * registry's "resource:operation" scopes: backend module runs, REST tokens
 * (token scopes ∩ user scopes) and CLI --as-user all pass through here.
 */
final class BackendUserScopeResolver
{
    public const GROUP_SCOPES_FIELD = 'tx_abilities_scopes';

    public function __construct(
        private readonly ConnectionPool $connectionPool,
    ) {
    }

    /**
     * Scopes of an authenticated (booted) backend user. Uses the group rows
     * fetchGroupData() already loaded; falls back to the database when the
     * groups have not been fetched yet.
     *
     * @return list<string>
     */
    public function resolveForUser(BackendUserAuthentication $user): array
    {
        if ($user->isAdmin()) {
            return [ExecutionContext::SCOPE_WILDCARD];
        }

        if ($user->userGroups !== []) {
            return self::scopesFromGroupRows($user->userGroups);
        }

        $uid = $user->user['uid'] ?? 0;

        return is_numeric($uid) && (int)$uid > 0 ? $this->resolveForUserUid((int)$uid) : [];
    }

    /**
     * @return list<string> empty when the user does not exist
     */
    public function resolveForUserUid(int $uid): array
    {
        $record = $this->findUser('uid', $uid);

        return $record === null ? [] : $this->resolveForUserRecord($record);
    }

    /**
     * @param array<string, mixed> $userRecord a be_users row (uid, admin, usergroup)
     * @return list<string>
     */
    public function resolveForUserRecord(array $userRecord): array
    {
        if ((int)($userRecord['admin'] ?? 0) === 1) {
            return [ExecutionContext::SCOPE_WILDCARD];
        }

        $groupIds = GeneralUtility::intExplode(',', (string)($userRecord['usergroup'] ?? ''), true);

        return self::scopesFromGroupRows($this->loadGroupsRecursively($groupIds));
    }

    /**
     * @return array<string, mixed>|null the be_users row (uid, username, admin, usergroup) or null
     */
    public function findUserByUsername(string $username): ?array
    {
        return $this->findUser('username', $username);
    }

    /**
     * Pure part of the resolution: union of the scope lists of the given
     * be_groups rows, deduplicated and sorted. "*" collapses the list.
     *
     * @param iterable<array<string, mixed>> $groupRows
     * @return list<string>
     */
    public static function scopesFromGroupRows(iterable $groupRows): array
    {
        $scopes = [];
        foreach ($groupRows as $row) {
            $raw = $row[self::GROUP_SCOPES_FIELD] ?? '';
            foreach (GeneralUtility::trimExplode(',', is_string($raw) ? $raw : '', true) as $scope) {
                if ($scope === ExecutionContext::SCOPE_WILDCARD) {
                    return [ExecutionContext::SCOPE_WILDCARD];
                }
                $scopes[$scope] = true;
            }
        }
        ksort($scopes);

        return array_keys($scopes);
    }

    /**
     * Intersection of two grant lists, wildcard-aware: "*" on one side
     * yields the other side, a "resource:*" grant covers every scope of
     * that resource.
     *
     * @param list<string> $a
     * @param list<string> $b
     * @return list<string>
     */
    public static function intersect(array $a, array $b): array
    {
        if (in_array(ExecutionContext::SCOPE_WILDCARD, $a, true)) {
            return array_values(array_unique($b));
        }
        if (in_array(ExecutionContext::SCOPE_WILDCARD, $b, true)) {
            return array_values(array_unique($a));
        }

        $grantedByB = new ExecutionContext(ExecutionContext::SURFACE_PHP, $b);
        $grantedByA = new ExecutionContext(ExecutionContext::SURFACE_PHP, $a);
        $result = [];
        foreach ($a as $scope) {
            if ($grantedByB->hasScope($scope)) {
                $result[$scope] = true;
            }
        }
        foreach ($b as $scope) {
            if ($grantedByA->hasScope($scope)) {
                $result[$scope] = true;
            }
        }
        ksort($result);

        return array_keys($result);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findUser(string $field, int|string $value): ?array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('be_users');
        $row = $queryBuilder
            ->select('uid', 'username', 'admin', 'usergroup')
            ->from('be_users')
            ->where(
                $queryBuilder->expr()->eq(
                    $field,
                    $queryBuilder->createNamedParameter($value, is_int($value) ? Connection::PARAM_INT : Connection::PARAM_STR),
                ),
                $queryBuilder->expr()->eq('deleted', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('disable', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
            )
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();

        return is_array($row) ? $row : null;
    }

    /**
     * @param list<int> $groupIds
     * @param array<int, true> $seen
     * @return list<array<string, mixed>>
     */
    private function loadGroupsRecursively(array $groupIds, array &$seen = []): array
    {
        $groupIds = array_values(array_filter($groupIds, static fn(int $id): bool => $id > 0 && !isset($seen[$id])));
        if ($groupIds === []) {
            return [];
        }
        foreach ($groupIds as $id) {
            $seen[$id] = true;
        }

        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('be_groups');
        $rows = $queryBuilder
            ->select('uid', 'subgroup', self::GROUP_SCOPES_FIELD)
            ->from('be_groups')
            ->where(
                $queryBuilder->expr()->in('uid', $queryBuilder->createNamedParameter($groupIds, Connection::PARAM_INT_ARRAY)),
                $queryBuilder->expr()->eq('deleted', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('hidden', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
            )
            ->executeQuery()
            ->fetchAllAssociative();

        $result = [];
        foreach ($rows as $row) {
            $result[] = $row;
            $subgroups = GeneralUtility::intExplode(',', (string)($row['subgroup'] ?? ''), true);
            foreach ($this->loadGroupsRecursively($subgroups, $seen) as $subgroupRow) {
                $result[] = $subgroupRow;
            }
        }

        return $result;
    }
}
