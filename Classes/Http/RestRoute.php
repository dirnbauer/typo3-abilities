<?php

declare(strict_types=1);

namespace Webconsulting\Abilities\Http;

/**
 * A matched REST route: which endpoint, with which path parameters.
 */
final readonly class RestRoute
{
    public const LIST = 'list';
    public const DESCRIBE = 'describe';
    public const RUN = 'run';
    public const CATEGORIES = 'categories';
    public const CATEGORY = 'category';

    /**
     * @param array<string, string> $params
     */
    public function __construct(
        public string $name,
        public array $params = [],
    ) {
    }

    public function param(string $name): string
    {
        return $this->params[$name] ?? '';
    }
}
