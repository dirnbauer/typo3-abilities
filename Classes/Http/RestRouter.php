<?php

declare(strict_types=1);

namespace Webconsulting\Abilities\Http;

/**
 * Maps request paths below the configured base path onto the REST
 * projection's endpoints (WordPress Abilities REST API layout):
 *
 *   GET    {base}/abilities                       list
 *   GET    {base}/abilities/{ns}/{name}           describe
 *   *      {base}/abilities/{ns}/{name}/run       run (method by annotation)
 *   GET    {base}/categories                      categories
 *   GET    {base}/categories/{slug}               category
 */
final class RestRouter
{
    private const SEGMENT = '[a-z0-9][a-z0-9\-]*';

    public function isApiRequest(string $path, string $basePath): bool
    {
        $path = rtrim($path, '/');
        $basePath = rtrim($basePath, '/');

        return $path === $basePath || str_starts_with($path, $basePath . '/');
    }

    public function match(string $path, string $basePath): ?RestRoute
    {
        if (!$this->isApiRequest($path, $basePath)) {
            return null;
        }

        $relative = trim(substr(rtrim($path, '/'), strlen(rtrim($basePath, '/'))), '/');

        if ($relative === 'abilities') {
            return new RestRoute(RestRoute::LIST);
        }
        if ($relative === 'categories') {
            return new RestRoute(RestRoute::CATEGORIES);
        }
        if (preg_match('#^categories/(' . self::SEGMENT . ')$#', $relative, $matches) === 1) {
            return new RestRoute(RestRoute::CATEGORY, ['slug' => $matches[1]]);
        }
        if (preg_match('#^abilities/(' . self::SEGMENT . ')/(' . self::SEGMENT . ')(/run)?$#', $relative, $matches) === 1) {
            $params = ['namespace' => $matches[1], 'name' => $matches[2], 'ability' => $matches[1] . '/' . $matches[2]];

            return new RestRoute(($matches[3] ?? '') === '/run' ? RestRoute::RUN : RestRoute::DESCRIBE, $params);
        }

        return null;
    }
}
