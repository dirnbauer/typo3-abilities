<?php

declare(strict_types=1);

namespace Webconsulting\Abilities\Http;

use Webconsulting\Abilities\Domain\ExecutionContext;

/**
 * The authenticated caller of a REST request: the backend user the request
 * acts as and the effective scopes (token scopes ∩ user scopes for bearer
 * tokens, the user's own scopes for a same-origin backend session).
 */
final readonly class RestIdentity
{
    public const VIA_TOKEN = 'token';
    public const VIA_SESSION = 'session';

    /**
     * @param list<string> $scopes
     */
    public function __construct(
        public int $backendUserUid,
        public string $username,
        public array $scopes,
        public string $via,
        public ?int $tokenUid = null,
    ) {
    }

    public function executionContext(): ExecutionContext
    {
        return ExecutionContext::rest($this->scopes, $this->backendUserUid);
    }
}
