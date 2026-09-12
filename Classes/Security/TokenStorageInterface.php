<?php

declare(strict_types=1);

namespace Webconsulting\Abilities\Security;

/**
 * Persistence seam of the TokenService: the database implementation talks
 * to tx_abilities_token, tests use an in-memory one.
 */
interface TokenStorageInterface
{
    /**
     * @return array<string, mixed>|null the non-deleted row with this hash
     */
    public function findByHash(string $tokenHash): ?array;

    /**
     * @param array<string, mixed> $fields
     * @return int the new uid
     */
    public function insert(array $fields): int;

    /**
     * @return list<array<string, mixed>> non-deleted rows, newest first
     */
    public function findAll(): array;

    /**
     * Soft-delete the token; returns false when no active token had this uid.
     */
    public function revoke(int $uid): bool;

    public function touch(int $uid, int $timestamp): void;
}
