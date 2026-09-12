<?php

declare(strict_types=1);

namespace Webconsulting\Abilities\Tests\Functional\Permission;

use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;
use Webconsulting\Abilities\Permission\BackendUserScopeResolver;

final class BackendUserScopeResolverTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = ['webconsulting/typo3-abilities'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/be_users.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/be_groups.csv');
    }

    private function resolver(): BackendUserScopeResolver
    {
        $resolver = $this->get(BackendUserScopeResolver::class);
        self::assertInstanceOf(BackendUserScopeResolver::class, $resolver);

        return $resolver;
    }

    #[Test]
    public function resolvesGroupScopesIncludingSubgroupsFromTheDatabase(): void
    {
        self::assertSame(['abilities:read', 'content:read', 'news:read', 'news:write', 'pages:write', 'system:read'], $this->resolver()->resolveForUserUid(2));
        self::assertSame(['*'], $this->resolver()->resolveForUserUid(1), 'admins hold every scope');
        self::assertSame([], $this->resolver()->resolveForUserUid(999));
    }

    #[Test]
    public function findsEnabledUsersByUsername(): void
    {
        $editor = $this->resolver()->findUserByUsername('editor');
        self::assertNotNull($editor);
        self::assertSame(2, (int)$editor['uid']);
        self::assertSame(['abilities:read', 'content:read', 'news:read', 'news:write', 'pages:write', 'system:read'], $this->resolver()->resolveForUserRecord($editor));

        self::assertNull($this->resolver()->findUserByUsername('disabled'));
        self::assertNull($this->resolver()->findUserByUsername('ghost'));
    }

    #[Test]
    public function resolvesFromABootedBackendUser(): void
    {
        $editor = $this->setUpBackendUser(2);

        self::assertSame(['abilities:read', 'content:read', 'news:read', 'news:write', 'pages:write', 'system:read'], $this->resolver()->resolveForUser($editor));

        $admin = $this->setUpBackendUser(1);
        self::assertSame(['*'], $this->resolver()->resolveForUser($admin));
    }
}
