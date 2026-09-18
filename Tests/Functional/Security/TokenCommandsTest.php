<?php

declare(strict_types=1);

namespace Webconsulting\Abilities\Tests\Functional\Security;

use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Console\Tester\CommandTester;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;
use Webconsulting\Abilities\Projection\Cli\TokenCreateCommand;
use Webconsulting\Abilities\Projection\Cli\TokenListCommand;
use Webconsulting\Abilities\Projection\Cli\TokenRevokeCommand;
use Webconsulting\Abilities\Security\TokenService;
use Webconsulting\Abilities\Tests\Support\TypeNarrowing;

final class TokenCommandsTest extends FunctionalTestCase
{
    use TypeNarrowing;

    protected array $testExtensionsToLoad = ['webconsulting/typo3-abilities'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/be_users.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/be_groups.csv');
    }

    #[Test]
    public function createsListsAuthenticatesAndRevokesTokens(): void
    {
        $create = $this->get(TokenCreateCommand::class);
        $list = $this->get(TokenListCommand::class);
        $revoke = $this->get(TokenRevokeCommand::class);
        $service = $this->get(TokenService::class);
        self::assertInstanceOf(TokenCreateCommand::class, $create);
        self::assertInstanceOf(TokenListCommand::class, $list);
        self::assertInstanceOf(TokenRevokeCommand::class, $revoke);
        self::assertInstanceOf(TokenService::class, $service);

        $tester = new CommandTester($create);
        self::assertSame(0, $tester->execute([
            '--user' => 'editor',
            '--scopes' => 'news:read,system:read,pages:read',
            '--name' => 'ci',
            '--expires' => '30',
            '--json' => true,
        ]), $tester->getDisplay());
        $issued = self::decodeJson($tester->getDisplay());
        $plaintext = self::asString($issued['token']);
        self::assertStringStartsWith('abl_', $plaintext);
        self::assertSame(2, $issued['beUser']);
        self::assertSame(['news:read', 'pages:read', 'system:read'], $issued['scopes']);
        self::assertSame(['news:read', 'system:read'], $issued['effectiveScopes'], 'pages:read is not held by the user');
        self::assertGreaterThan(time(), self::asArray($issued)['expires']);

        $row = $this->getConnectionPool()->getConnectionForTable('tx_abilities_token')
            ->select(['*'], 'tx_abilities_token', ['uid' => $issued['uid']])->fetchAssociative();
        self::assertIsArray($row);
        self::assertSame(hash('sha256', $plaintext), $row['token_hash']);
        self::assertStringNotContainsString($plaintext, implode('|', array_map(strval(...), array_filter($row, is_scalar(...)))));

        $token = $service->authenticate($plaintext);
        self::assertNotNull($token);
        self::assertSame(2, $token->backendUserUid);

        $listTester = new CommandTester($list);
        self::assertSame(0, $listTester->execute([]));
        self::assertStringContainsString('ci', $listTester->getDisplay());
        self::assertStringNotContainsString($plaintext, $listTester->getDisplay());

        $revokeTester = new CommandTester($revoke);
        self::assertSame(0, $revokeTester->execute(['uid' => (string)$issued['uid']]));
        self::assertNull($service->authenticate($plaintext));

        $unknownUser = new CommandTester($create);
        self::assertSame(2, $unknownUser->execute(['--user' => 'ghost', '--name' => 'x']));
    }
}
