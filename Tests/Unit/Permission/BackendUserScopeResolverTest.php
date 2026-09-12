<?php

declare(strict_types=1);

namespace Webconsulting\Abilities\Tests\Unit\Permission;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Webconsulting\Abilities\Permission\BackendUserScopeResolver;

/**
 * The pure parts of the resolver; the be_users/be_groups lookups are
 * covered by the functional test.
 */
final class BackendUserScopeResolverTest extends TestCase
{
    #[Test]
    public function unionsGroupScopesDeduplicatedAndSorted(): void
    {
        $scopes = BackendUserScopeResolver::scopesFromGroupRows([
            ['uid' => 1, 'tx_abilities_scopes' => 'news:write, system:read'],
            ['uid' => 2, 'tx_abilities_scopes' => 'news:read,news:write'],
            ['uid' => 3, 'tx_abilities_scopes' => ''],
            ['uid' => 4],
            ['uid' => 5, 'tx_abilities_scopes' => null],
        ]);

        self::assertSame(['news:read', 'news:write', 'system:read'], $scopes);
    }

    #[Test]
    public function wildcardCollapsesTheList(): void
    {
        $scopes = BackendUserScopeResolver::scopesFromGroupRows([
            ['tx_abilities_scopes' => 'news:read'],
            ['tx_abilities_scopes' => '*'],
        ]);

        self::assertSame(['*'], $scopes);
        self::assertSame([], BackendUserScopeResolver::scopesFromGroupRows([]));
    }

    #[Test]
    public function intersectIsWildcardAware(): void
    {
        self::assertSame(['a:b', 'c:d'], BackendUserScopeResolver::intersect(['*'], ['c:d', 'a:b', 'a:b']));
        self::assertSame(['a:b'], BackendUserScopeResolver::intersect(['a:b'], ['*']));
        self::assertSame(['*'], BackendUserScopeResolver::intersect(['*'], ['*']));
        self::assertSame(['news:read'], BackendUserScopeResolver::intersect(['news:read', 'pages:read'], ['news:*']));
        self::assertSame(['news:*', 'news:read'], BackendUserScopeResolver::intersect(['news:*'], ['news:*', 'news:read']));
        self::assertSame([], BackendUserScopeResolver::intersect(['a:b'], ['c:d']));
        self::assertSame([], BackendUserScopeResolver::intersect([], ['*']));
    }
}
