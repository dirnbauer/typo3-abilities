<?php

declare(strict_types=1);

namespace Webconsulting\Abilities\Security;

/**
 * One tx_abilities_token row: an opaque bearer token bound to a backend
 * user, carrying its own scope list. The plaintext is never stored — only
 * the SHA-256 hash — so a token can be shown exactly once, at creation.
 */
final readonly class Token
{
    /**
     * @param list<string> $scopes
     */
    public function __construct(
        public int $uid,
        public string $name,
        public int $backendUserUid,
        public array $scopes,
        public int $expires,
        public int $lastUsed,
        public int $crdate,
    ) {
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        $scopes = $row['scopes'] ?? '';

        return new self(
            uid: self::int($row['uid'] ?? 0),
            name: is_string($row['name'] ?? null) ? $row['name'] : '',
            backendUserUid: self::int($row['be_user'] ?? 0),
            scopes: self::normalizeScopes(is_string($scopes) ? explode(',', $scopes) : []),
            expires: self::int($row['expires'] ?? 0),
            lastUsed: self::int($row['last_used'] ?? 0),
            crdate: self::int($row['crdate'] ?? 0),
        );
    }

    public function isExpired(?int $now = null): bool
    {
        return $this->expires > 0 && $this->expires <= ($now ?? time());
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'uid' => $this->uid,
            'name' => $this->name,
            'beUser' => $this->backendUserUid,
            'scopes' => $this->scopes,
            'expires' => $this->expires,
            'lastUsed' => $this->lastUsed,
            'crdate' => $this->crdate,
        ];
    }

    /**
     * Trimmed, deduplicated, sorted scope list.
     *
     * @param list<string> $scopes
     * @return list<string>
     */
    public static function normalizeScopes(array $scopes): array
    {
        $normalized = [];
        foreach ($scopes as $scope) {
            $scope = trim($scope);
            if ($scope !== '') {
                $normalized[$scope] = true;
            }
        }
        ksort($normalized);

        return array_keys($normalized);
    }

    private static function int(mixed $value): int
    {
        return is_numeric($value) ? (int)$value : 0;
    }
}
