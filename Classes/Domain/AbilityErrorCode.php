<?php

declare(strict_types=1);

namespace Webconsulting\Abilities\Domain;

/**
 * Stable, machine-readable error codes of the execution pipeline. The
 * vocabulary mirrors the WordPress Abilities API (ability_invalid_input,
 * ability_invalid_permissions, ability_invalid_output) and adds the
 * governance outcomes this registry knows on top.
 */
enum AbilityErrorCode: string
{
    /** Input violated the input schema; nothing ran. */
    case InvalidInput = 'ability_invalid_input';

    /** Scope or ability permission check failed; nothing ran. */
    case InvalidPermissions = 'ability_invalid_permissions';

    /** The ability ran (side effects may have happened!) but its output violates the contract. */
    case InvalidOutput = 'ability_invalid_output';

    /** The ability threw during execution. */
    case CannotExecute = 'ability_cannot_execute';

    /** Blocked by the abilities policy (or a BeforeAbilityExecutionEvent listener) before anything ran. */
    case PolicyDenied = 'ability_policy_denied';

    /** Policy requires an explicit human approval the execution context does not carry. */
    case ReviewRequired = 'ability_review_required';

    /** No ability with that name is registered (or it is not exposed to the surface). */
    case NotFound = 'ability_not_found';

    public function httpStatus(): int
    {
        return match ($this) {
            self::InvalidInput => 400,
            self::InvalidPermissions, self::PolicyDenied => 403,
            self::NotFound => 404,
            self::ReviewRequired => 409,
            self::InvalidOutput, self::CannotExecute => 500,
        };
    }

    /**
     * HTTP status for a raw error code string; unknown codes are treated
     * as server-side failures.
     */
    public static function httpStatusFor(?string $code): int
    {
        return $code === null ? 500 : (self::tryFrom($code)?->httpStatus() ?? 500);
    }
}
