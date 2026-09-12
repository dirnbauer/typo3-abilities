<?php

declare(strict_types=1);

namespace Webconsulting\Abilities\Security;

/**
 * A freshly created token together with the one and only copy of its
 * plaintext. Hand the plaintext to the caller, then forget it.
 */
final readonly class IssuedToken
{
    public function __construct(
        public Token $token,
        public string $plaintext,
    ) {}
}
