<?php

declare(strict_types=1);

namespace Webconsulting\Abilities\Tests\Unit\Http;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Webconsulting\Abilities\Http\RestEndpoint;
use Webconsulting\Abilities\Http\RestRouter;

final class RestRouterTest extends TestCase
{
    /**
     * @return iterable<string, array{string, RestEndpoint|null, array<string, string>}>
     */
    public static function paths(): iterable
    {
        yield 'list' => ['/abilities/v1/abilities', RestEndpoint::Listing, []];
        yield 'list trailing slash' => ['/abilities/v1/abilities/', RestEndpoint::Listing, []];
        yield 'describe' => ['/abilities/v1/abilities/system/site-info', RestEndpoint::Describe, ['namespace' => 'system', 'name' => 'site-info', 'ability' => 'system/site-info']];
        yield 'run' => ['/abilities/v1/abilities/system/site-info/run', RestEndpoint::Run, ['namespace' => 'system', 'name' => 'site-info', 'ability' => 'system/site-info']];
        yield 'categories' => ['/abilities/v1/categories', RestEndpoint::Categories, []];
        yield 'category' => ['/abilities/v1/categories/content', RestEndpoint::Category, ['slug' => 'content']];
        yield 'catalog' => ['/abilities/v1/catalog', RestEndpoint::Catalog, []];
        yield 'base only' => ['/abilities/v1', null, []];
        yield 'unknown below base' => ['/abilities/v1/whatever', null, []];
        yield 'uppercase name' => ['/abilities/v1/abilities/System/Info', null, []];
        yield 'too deep' => ['/abilities/v1/abilities/a/b/c', null, []];
    }

    /**
     * @param array<string, string> $params
     */
    #[Test]
    #[DataProvider('paths')]
    public function matchesRoutesBelowTheBasePath(string $path, ?RestEndpoint $route, array $params): void
    {
        $matched = new RestRouter()->match($path, '/abilities/v1');

        if ($route === null) {
            self::assertNull($matched);

            return;
        }
        self::assertNotNull($matched);
        self::assertSame($route, $matched->endpoint);
        self::assertSame($params, $matched->params);
    }

    #[Test]
    public function ignoresPathsOutsideTheBase(): void
    {
        $router = new RestRouter();

        self::assertFalse($router->isApiRequest('/abilities', '/abilities/v1'));
        self::assertFalse($router->isApiRequest('/abilities/v10/abilities', '/abilities/v1'));
        self::assertFalse($router->isApiRequest('/', '/abilities/v1'));
        self::assertTrue($router->isApiRequest('/abilities/v1', '/abilities/v1'));
        self::assertTrue($router->isApiRequest('/api/x/abilities', '/api/x/'));
        self::assertNull($router->match('/other/abilities', '/abilities/v1'));
        self::assertSame(RestEndpoint::Listing, $router->match('/api/x/abilities', '/api/x')?->endpoint);
    }
}
