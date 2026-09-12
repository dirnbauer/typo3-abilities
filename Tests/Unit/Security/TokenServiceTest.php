<?php

declare(strict_types=1);

namespace Webconsulting\Abilities\Tests\Unit\Security;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Webconsulting\Abilities\Security\Token;
use Webconsulting\Abilities\Security\TokenService;
use Webconsulting\Abilities\Tests\Fixtures\InMemoryTokenStorage;

final class TokenServiceTest extends TestCase
{
    private InMemoryTokenStorage $storage;

    private TokenService $service;

    protected function setUp(): void
    {
        $this->storage = new InMemoryTokenStorage();
        $this->service = new TokenService($this->storage);
    }

    #[Test]
    public function createStoresOnlyTheSha256HashAndNormalizesScopes(): void
    {
        $issued = $this->service->create(' n8n ', 3, ['news:write', ' system:read', 'news:write', ''], null, 1_000);

        self::assertStringStartsWith(TokenService::TOKEN_PREFIX, $issued->plaintext);
        self::assertSame(68, strlen($issued->plaintext));
        self::assertSame('n8n', $issued->token->name);
        self::assertSame(3, $issued->token->backendUserUid);
        self::assertSame(['news:write', 'system:read'], $issued->token->scopes);
        self::assertSame(0, $issued->token->expires);
        self::assertSame(1_000, $issued->token->crdate);

        $row = $this->storage->rows[$issued->token->uid];
        self::assertSame(hash('sha256', $issued->plaintext), $row['token_hash']);
        self::assertSame('news:write,system:read', $row['scopes']);
        foreach ($row as $value) {
            self::assertNotSame($issued->plaintext, $value, 'plaintext must never be persisted');
        }
    }

    #[Test]
    public function authenticateRoundTripsAndTouchesLastUsed(): void
    {
        $issued = $this->service->create('ci', 5, ['abilities:read'], null, 1_000);

        $token = $this->service->authenticate($issued->plaintext, 2_000);

        self::assertInstanceOf(Token::class, $token);
        self::assertSame($issued->token->uid, $token->uid);
        self::assertSame(5, $token->backendUserUid);
        self::assertSame(['abilities:read'], $token->scopes);
        self::assertSame(2_000, $this->storage->rows[$token->uid]['last_used']);
    }

    #[Test]
    public function unknownEmptyExpiredAndRevokedTokensDoNotAuthenticate(): void
    {
        self::assertNull($this->service->authenticate(''));
        self::assertNull($this->service->authenticate('abl_' . str_repeat('0', 64)));

        $expired = $this->service->create('old', 1, [], 1_500, 1_000);
        self::assertNull($this->service->authenticate($expired->plaintext, 1_500));
        self::assertNotNull($this->service->authenticate($expired->plaintext, 1_499));

        $revoked = $this->service->create('gone', 1, ['x:y']);
        self::assertTrue($this->service->revoke($revoked->token->uid));
        self::assertFalse($this->service->revoke($revoked->token->uid), 'revoking twice reports no active token');
        self::assertNull($this->service->authenticate($revoked->plaintext));
    }

    #[Test]
    public function listOmitsRevokedTokensNewestFirst(): void
    {
        $first = $this->service->create('first', 1, []);
        $second = $this->service->create('second', 1, []);
        $third = $this->service->create('third', 1, []);
        $this->service->revoke($second->token->uid);

        $names = array_map(static fn(Token $token): string => $token->name, $this->service->list());

        self::assertSame(['third', 'first'], $names);
        self::assertSame($third->token->uid, $this->service->list()[0]->uid);
        self::assertSame($first->token->uid, $this->service->list()[1]->uid);
    }

    #[Test]
    public function rejectsNamelessOrUnboundTokens(): void
    {
        try {
            $this->service->create('   ', 1, []);
            self::fail('expected exception');
        } catch (\InvalidArgumentException $exception) {
            self::assertSame(7480291020, $exception->getCode());
        }

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionCode(7480291021);
        $this->service->create('x', 0, []);
    }

    #[Test]
    public function tokenRowMappingIsDefensive(): void
    {
        $token = Token::fromRow(['uid' => '7', 'name' => 'n', 'be_user' => '2', 'scopes' => 'b:c, a:b,,', 'expires' => 'x', 'last_used' => null]);

        self::assertSame(7, $token->uid);
        self::assertSame(2, $token->backendUserUid);
        self::assertSame(['a:b', 'b:c'], $token->scopes);
        self::assertSame(0, $token->expires);
        self::assertFalse($token->isExpired());
        self::assertSame(['uid' => 7, 'name' => 'n', 'beUser' => 2, 'scopes' => ['a:b', 'b:c'], 'expires' => 0, 'lastUsed' => 0, 'crdate' => 0], $token->toArray());
    }
}
