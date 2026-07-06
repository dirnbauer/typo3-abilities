<?php

declare(strict_types=1);

namespace Webconsulting\Abilities\Domain;

/**
 * Who is executing an ability, from which surface, with which grants.
 *
 * $grantedScopes semantics:
 *  - null: trusted surface (CLI as _cli_ admin, or an MCP session that the
 *    MCP server has already authenticated and gated) — scope checks are
 *    skipped, policy and the ability's own permission check still apply.
 *  - array: explicit grant list; every scope the ability declares must be
 *    present or execution is denied.
 */
final readonly class ExecutionContext
{
    public const SURFACE_CLI = 'cli';
    public const SURFACE_MCP = 'mcp';
    public const SURFACE_REST = 'rest';
    public const SURFACE_PHP = 'php';

    /**
     * @param list<string>|null $grantedScopes
     */
    public function __construct(
        public string $surface = self::SURFACE_PHP,
        public ?array $grantedScopes = null,
        public bool $reviewApproved = false,
    ) {
    }

    public static function cli(bool $reviewApproved = false): self
    {
        return new self(self::SURFACE_CLI, null, $reviewApproved);
    }

    public static function mcp(): self
    {
        return new self(self::SURFACE_MCP, null, false);
    }

    public function hasScope(string $scope): bool
    {
        return $this->grantedScopes === null || in_array($scope, $this->grantedScopes, true);
    }

    /**
     * @param list<string> $scopes
     * @return list<string> scopes that are required but not granted
     */
    public function missingScopes(array $scopes): array
    {
        if ($this->grantedScopes === null) {
            return [];
        }

        return array_values(array_filter($scopes, fn(string $scope): bool => !$this->hasScope($scope)));
    }
}
