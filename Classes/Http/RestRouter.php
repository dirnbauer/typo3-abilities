<?php

declare(strict_types=1);

namespace Webconsulting\Abilities\Http;

/**
 * Maps request paths below the configured base path onto the REST
 * projection's endpoints:
 *
 *   GET    {base}/abilities                       Listing
 *   GET    {base}/abilities/{ns}/{name}           Describe
 *   *      {base}/abilities/{ns}/{name}/run       Run (method by annotation)
 *   GET    {base}/categories                      Categories
 *   GET    {base}/categories/{slug}               Category
 *   GET    {base}/catalog                         Catalog
 */
final class RestRouter
{
    private const string SEGMENT = '[a-z0-9][a-z0-9\-]*';

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
            return new RestRoute(RestEndpoint::Listing);
        }
        if ($relative === 'categories') {
            return new RestRoute(RestEndpoint::Categories);
        }
        if ($relative === 'catalog') {
            return new RestRoute(RestEndpoint::Catalog);
        }
        if (preg_match('#^categories/(' . self::SEGMENT . ')$#', $relative, $matches) === 1) {
            return new RestRoute(RestEndpoint::Category, ['slug' => $matches[1]]);
        }
        if (preg_match('#^abilities/(' . self::SEGMENT . ')/(' . self::SEGMENT . ')(/run)?$#', $relative, $matches) === 1) {
            $params = ['namespace' => $matches[1], 'name' => $matches[2], 'ability' => $matches[1] . '/' . $matches[2]];

            return new RestRoute(($matches[3] ?? '') === '/run' ? RestEndpoint::Run : RestEndpoint::Describe, $params);
        }

        return null;
    }
}
