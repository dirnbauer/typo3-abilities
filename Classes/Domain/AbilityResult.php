<?php

declare(strict_types=1);

namespace Webconsulting\Abilities\Domain;

/**
 * Result envelope of one ability execution.
 *
 * Error codes are the stable identifiers of AbilityErrorCode:
 *  - ability_policy_denied: blocked by the abilities policy before anything ran
 *  - ability_review_required: policy wants a human approval the context lacks
 *  - ability_invalid_input: input violated the input schema; nothing ran
 *  - ability_invalid_permissions: scope or ability permission check failed; nothing ran
 *  - ability_cannot_execute: the ability threw
 *  - ability_invalid_output: the ability ran (side effects may have happened!) but
 *    returned data violating its output contract
 *  - ability_not_found: the requested ability is not registered
 */
final readonly class AbilityResult
{
    public const ERROR_POLICY_DENIED = 'ability_policy_denied';
    public const ERROR_REVIEW_REQUIRED = 'ability_review_required';
    public const ERROR_INVALID_INPUT = 'ability_invalid_input';
    public const ERROR_PERMISSION_DENIED = 'ability_invalid_permissions';
    public const ERROR_EXECUTION_ERROR = 'ability_cannot_execute';
    public const ERROR_INVALID_OUTPUT = 'ability_invalid_output';
    public const ERROR_NOT_FOUND = 'ability_not_found';

    private function __construct(
        public bool $ok,
        public mixed $data,
        public ?string $errorCode,
        public ?string $error,
    ) {}

    public static function success(mixed $data): self
    {
        return new self(true, $data, null, null);
    }

    public static function failure(string|AbilityErrorCode $errorCode, string $error): self
    {
        return new self(false, null, $errorCode instanceof AbilityErrorCode ? $errorCode->value : $errorCode, $error);
    }

    public function errorCodeEnum(): ?AbilityErrorCode
    {
        return $this->errorCode === null ? null : AbilityErrorCode::tryFrom($this->errorCode);
    }

    /**
     * HTTP status this result maps to on HTTP surfaces (REST, backend AJAX).
     */
    public function httpStatus(): int
    {
        return $this->ok ? 200 : AbilityErrorCode::httpStatusFor($this->errorCode);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->ok
            ? ['ok' => true, 'data' => $this->data]
            : ['ok' => false, 'errorCode' => $this->errorCode, 'error' => $this->error];
    }
}
