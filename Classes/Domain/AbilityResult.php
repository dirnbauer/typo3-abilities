<?php

declare(strict_types=1);

namespace Webconsulting\Abilities\Domain;

/**
 * Result envelope of one ability execution, identical on every surface:
 *   {"ok": true,  "data": ...}
 *   {"ok": false, "errorCode": "ability_...", "error": "why"}
 *
 * $errorCode is the string value of an AbilityErrorCode; the ERROR_*
 * constants name the same values for callers that compare strings.
 */
final readonly class AbilityResult
{
    public const ERROR_POLICY_DENIED = AbilityErrorCode::PolicyDenied->value;
    public const ERROR_REVIEW_REQUIRED = AbilityErrorCode::ReviewRequired->value;
    public const ERROR_INVALID_INPUT = AbilityErrorCode::InvalidInput->value;
    public const ERROR_PERMISSION_DENIED = AbilityErrorCode::InvalidPermissions->value;
    public const ERROR_EXECUTION_ERROR = AbilityErrorCode::CannotExecute->value;
    public const ERROR_INVALID_OUTPUT = AbilityErrorCode::InvalidOutput->value;
    public const ERROR_NOT_FOUND = AbilityErrorCode::NotFound->value;

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

    public static function failure(AbilityErrorCode $errorCode, string $error): self
    {
        return new self(false, null, $errorCode->value, $error);
    }

    /**
     * HTTP status this result maps to on HTTP surfaces (REST, webhook, backend AJAX).
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
