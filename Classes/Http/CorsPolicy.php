<?php

declare(strict_types=1);

namespace Webconsulting\Abilities\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * CORS for the REST projection, driven by the restCorsOrigins extension
 * setting: a comma-separated origin allow-list, "*" for any origin, empty
 * to emit no CORS headers at all (same-origin and non-browser clients only).
 */
final readonly class CorsPolicy
{
    private const ALLOWED_METHODS = 'GET, POST, DELETE, OPTIONS';
    private const ALLOWED_HEADERS = 'Authorization, Content-Type, X-Requested-With, X-TYPO3-Workspace';
    private const EXPOSED_HEADERS = 'X-Total, X-Total-Pages, Allow';

    /**
     * @param list<string> $allowedOrigins
     */
    public function __construct(
        public array $allowedOrigins,
    ) {}

    public static function fromString(string $origins): self
    {
        $list = [];
        foreach (explode(',', $origins) as $origin) {
            $origin = rtrim(trim($origin), '/');
            if ($origin !== '') {
                $list[] = $origin;
            }
        }

        return new self($list);
    }

    public function isEnabled(): bool
    {
        return $this->allowedOrigins !== [];
    }

    public function allowsOrigin(string $origin): bool
    {
        $origin = rtrim(trim($origin), '/');
        if ($origin === '') {
            return false;
        }
        foreach ($this->allowedOrigins as $allowed) {
            if ($allowed === '*' || strcasecmp($allowed, $origin) === 0) {
                return true;
            }
        }

        return false;
    }

    public function apply(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $origin = $request->getHeaderLine('Origin');
        if (!$this->isEnabled() || !$this->allowsOrigin($origin)) {
            return $response;
        }

        return $response
            ->withHeader('Access-Control-Allow-Origin', in_array('*', $this->allowedOrigins, true) ? '*' : $origin)
            ->withHeader('Access-Control-Expose-Headers', self::EXPOSED_HEADERS)
            ->withAddedHeader('Vary', 'Origin');
    }

    public function preflight(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $response = $this->apply($request, $response);
        if (!$response->hasHeader('Access-Control-Allow-Origin')) {
            return $response;
        }

        return $response
            ->withHeader('Access-Control-Allow-Methods', self::ALLOWED_METHODS)
            ->withHeader('Access-Control-Allow-Headers', self::ALLOWED_HEADERS)
            ->withHeader('Access-Control-Max-Age', '600');
    }
}
