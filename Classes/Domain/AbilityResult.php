<?php

declare(strict_types=1);

namespace Webconsulting\Abilities\Domain;

/**
 * Result envelope of one ability execution.
 *
 * Error codes are stable machine-readable identifiers:
 *  - policy_denied: blocked by the abilities policy before anything ran
 *  - invalid_input: input violated the input schema; nothing ran
 *  - permission_denied: scope or ability permission check failed; nothing ran
 *  - execution_error: the ability threw
 *  - invalid_output: the ability ran (side effects may have happened!) but
 *    returned data violating its output contract
 */
final readonly class AbilityResult
{
    public const ERROR_POLICY_DENIED = 'policy_denied';
    public const ERROR_INVALID_INPUT = 'invalid_input';
    public const ERROR_PERMISSION_DENIED = 'permission_denied';
    public const ERROR_EXECUTION_ERROR = 'execution_error';
    public const ERROR_INVALID_OUTPUT = 'invalid_output';

    private function __construct(
        public bool $ok,
        public mixed $data,
        public ?string $errorCode,
        public ?string $error,
    ) {
    }

    public static function success(mixed $data): self
    {
        return new self(true, $data, null, null);
    }

    public static function failure(string $errorCode, string $error): self
    {
        return new self(false, null, $errorCode, $error);
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
