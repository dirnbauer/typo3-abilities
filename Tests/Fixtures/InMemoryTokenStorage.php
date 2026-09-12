<?php

declare(strict_types=1);

namespace Webconsulting\Abilities\Tests\Fixtures;

use Webconsulting\Abilities\Security\TokenStorageInterface;

final class InMemoryTokenStorage implements TokenStorageInterface
{
    /** @var array<int, array<string, mixed>> */
    public array $rows = [];

    private int $nextUid = 1;

    public function findByHash(string $tokenHash): ?array
    {
        foreach ($this->rows as $row) {
            if (($row['token_hash'] ?? null) === $tokenHash && (int)($row['deleted'] ?? 0) === 0) {
                return $row;
            }
        }

        return null;
    }

    public function insert(array $fields): int
    {
        $uid = $this->nextUid++;
        $this->rows[$uid] = ['uid' => $uid, ...$fields];

        return $uid;
    }

    public function findAll(): array
    {
        $rows = array_values(array_filter($this->rows, static fn(array $row): bool => (int)($row['deleted'] ?? 0) === 0));
        usort($rows, static fn(array $a, array $b): int => (int)$b['uid'] <=> (int)$a['uid']);

        return $rows;
    }

    public function revoke(int $uid): bool
    {
        if (!isset($this->rows[$uid]) || (int)($this->rows[$uid]['deleted'] ?? 0) === 1) {
            return false;
        }
        $this->rows[$uid]['deleted'] = 1;

        return true;
    }

    public function touch(int $uid, int $timestamp): void
    {
        if (isset($this->rows[$uid])) {
            $this->rows[$uid]['last_used'] = $timestamp;
        }
    }
}
