<?php

declare(strict_types=1);

namespace Webconsulting\Abilities\Http;

/**
 * A matched REST route: which endpoint, with which path parameters.
 */
final readonly class RestRoute
{
    /**
     * @param array<string, string> $params
     */
    public function __construct(
        public RestEndpoint $endpoint,
        public array $params = [],
    ) {}

    public function param(string $name): string
    {
        return $this->params[$name] ?? '';
    }
}
