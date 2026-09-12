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
 *    present or execution is denied. "*" grants every scope, "news:*"
 *    grants every scope of the "news" resource.
 *
 * $backendUserUid is the acting TYPO3 backend user when the surface has
 * resolved one (backend module, REST token, CLI --as-user); traces record it.
 */
final readonly class ExecutionContext
{
    public const SURFACE_CLI = 'cli';
    public const SURFACE_MCP = 'mcp';
    public const SURFACE_REST = 'rest';
    public const SURFACE_PHP = 'php';
    public const SURFACE_BACKEND = 'backend';

    public const SCOPE_WILDCARD = '*';

    /**
     * @param list<string>|null $grantedScopes
     */
    public function __construct(
        public string $surface = self::SURFACE_PHP,
        public ?array $grantedScopes = null,
        public bool $reviewApproved = false,
        public ?int $backendUserUid = null,
    ) {}

    /**
     * @param list<string>|null $grantedScopes null = trusted CLI; a list when running --as-user
     */
    public static function cli(bool $reviewApproved = false, ?array $grantedScopes = null, ?int $backendUserUid = null): self
    {
        return new self(self::SURFACE_CLI, $grantedScopes, $reviewApproved, $backendUserUid);
    }

    public static function mcp(?int $backendUserUid = null): self
    {
        return new self(self::SURFACE_MCP, null, false, $backendUserUid);
    }

    /**
     * A logged-in backend user driving the registry from the TYPO3 backend
     * module. The BE session already authenticated the user; the surface
     * passes the scopes resolved from the user's be_groups so scope checks
     * apply like on every other surface (admins resolve to "*").
     *
     * @param list<string>|null $grantedScopes
     */
    public static function backend(bool $reviewApproved = false, ?array $grantedScopes = null, ?int $backendUserUid = null): self
    {
        return new self(self::SURFACE_BACKEND, $grantedScopes, $reviewApproved, $backendUserUid);
    }

    /**
     * REST is never trusted: it always carries an explicit grant list
     * (token scopes ∩ user scopes) and never a review approval.
     *
     * @param list<string> $grantedScopes
     */
    public static function rest(array $grantedScopes, ?int $backendUserUid = null): self
    {
        return new self(self::SURFACE_REST, $grantedScopes, false, $backendUserUid);
    }

    public function withBackendUser(?int $backendUserUid): self
    {
        return new self($this->surface, $this->grantedScopes, $this->reviewApproved, $backendUserUid);
    }

    /**
     * @param list<string>|null $grantedScopes
     */
    public function withGrantedScopes(?array $grantedScopes): self
    {
        return new self($this->surface, $grantedScopes, $this->reviewApproved, $this->backendUserUid);
    }

    public function withReviewApproved(bool $reviewApproved): self
    {
        return new self($this->surface, $this->grantedScopes, $reviewApproved, $this->backendUserUid);
    }

    public function isTrusted(): bool
    {
        return $this->grantedScopes === null;
    }

    public function hasScope(string $scope): bool
    {
        if ($this->grantedScopes === null) {
            return true;
        }

        foreach ($this->grantedScopes as $granted) {
            if ($granted === $scope || $granted === self::SCOPE_WILDCARD) {
                return true;
            }
            if (str_ends_with($granted, ':*') && str_starts_with($scope, substr($granted, 0, -1))) {
                return true;
            }
        }

        return false;
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
