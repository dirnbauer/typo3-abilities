<?php

declare(strict_types=1);

namespace Webconsulting\Abilities\Security;

/**
 * Opaque bearer tokens for the REST projection, bound to a backend user.
 *
 * Ported from sg_apicore's BackendBearerOpaqueTokenProvider: the plaintext
 * is generated once, only its SHA-256 hash is stored, lookup goes by hash
 * and the stored hash is re-compared in constant time; expired and revoked
 * (soft-deleted) tokens never authenticate.
 */
final class TokenService
{
    public const TOKEN_PREFIX = 'abl_';

    private const TOKEN_BYTES = 32;

    public function __construct(
        private readonly TokenStorageInterface $storage,
    ) {}

    public static function hash(string $plaintext): string
    {
        return hash('sha256', $plaintext);
    }

    public static function generate(): string
    {
        return self::TOKEN_PREFIX . bin2hex(random_bytes(self::TOKEN_BYTES));
    }

    /**
     * @param list<string> $scopes
     * @param int|null $expiresAt unix timestamp; null or 0 = never
     */
    public function create(
        string $name,
        int $backendUserUid,
        array $scopes,
        ?int $expiresAt = null,
        ?int $now = null,
    ): IssuedToken {
        if (trim($name) === '') {
            throw new \InvalidArgumentException('A token needs a name.', 7480291020);
        }
        if ($backendUserUid <= 0) {
            throw new \InvalidArgumentException('A token must be bound to a backend user.', 7480291021);
        }

        $now ??= time();
        $plaintext = self::generate();
        $scopes = Token::normalizeScopes($scopes);
        $expires = max(0, $expiresAt ?? 0);

        $uid = $this->storage->insert([
            'pid' => 0,
            'crdate' => $now,
            'tstamp' => $now,
            'name' => trim($name),
            'token_hash' => self::hash($plaintext),
            'be_user' => $backendUserUid,
            'scopes' => implode(',', $scopes),
            'expires' => $expires,
            'last_used' => 0,
            'deleted' => 0,
        ]);

        return new IssuedToken(
            new Token($uid, trim($name), $backendUserUid, $scopes, $expires, 0, $now),
            $plaintext,
        );
    }

    /**
     * Resolve a presented plaintext token; null for unknown, revoked or
     * expired tokens. A successful lookup records last_used.
     */
    public function authenticate(string $plaintext, ?int $now = null): ?Token
    {
        $plaintext = trim($plaintext);
        if ($plaintext === '') {
            return null;
        }

        $hash = self::hash($plaintext);
        $row = $this->storage->findByHash($hash);
        if ($row === null) {
            return null;
        }

        $storedHash = $row['token_hash'] ?? '';
        if (!is_string($storedHash) || !hash_equals($storedHash, $hash)) {
            return null;
        }

        $token = Token::fromRow($row);
        $now ??= time();
        if ($token->uid <= 0 || $token->backendUserUid <= 0 || $token->isExpired($now)) {
            return null;
        }

        $this->storage->touch($token->uid, $now);

        return $token;
    }

    /**
     * @return list<Token>
     */
    public function list(): array
    {
        return array_map(Token::fromRow(...), $this->storage->findAll());
    }

    public function revoke(int $uid): bool
    {
        return $this->storage->revoke($uid);
    }
}
