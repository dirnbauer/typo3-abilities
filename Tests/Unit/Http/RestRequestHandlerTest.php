<?php

declare(strict_types=1);

namespace Webconsulting\Abilities\Tests\Unit\Http;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Http\Stream;
use Webconsulting\Abilities\Catalog\CapabilityCatalog;
use Webconsulting\Abilities\Catalog\Source\AbilitiesSource;
use Webconsulting\Abilities\Category\CategoryRegistry;
use Webconsulting\Abilities\Domain\AbilityCategory;
use Webconsulting\Abilities\Domain\ExecutionContext;
use Webconsulting\Abilities\Execution\AbilityExecutor;
use Webconsulting\Abilities\Http\RestConfiguration;
use Webconsulting\Abilities\Http\RestInputMapper;
use Webconsulting\Abilities\Http\RestRequestHandler;
use Webconsulting\Abilities\Http\RestResponseFactory;
use Webconsulting\Abilities\Http\RestRoute;
use Webconsulting\Abilities\Http\RestRouter;
use Webconsulting\Abilities\Policy\PolicyProvider;
use Webconsulting\Abilities\Registry\AbilitiesRegistry;
use Webconsulting\Abilities\Tests\Fixtures\CallbackAbility;
use Webconsulting\Abilities\Tests\Fixtures\EchoAbility;
use Webconsulting\Abilities\Tests\Fixtures\HiddenAbility;
use Webconsulting\Abilities\Tests\Fixtures\WriteAbility;
use Webconsulting\Abilities\Tests\Support\TypeNarrowing;
use Webconsulting\Abilities\Validation\SchemaValidator;

/**
 * The REST endpoints without HTTP plumbing: routes are matched by the
 * router and handed to the handler with a ready identity. Authentication
 * and the middleware stack are covered by the functional test.
 */
final class RestRequestHandlerTest extends TestCase
{
    use TypeNarrowing;

    private RestRequestHandler $handler;

    private RestRouter $router;

    private string $policyFile;

    protected function setUp(): void
    {
        $this->policyFile = tempnam(sys_get_temp_dir(), 'abilities-policy-') . '.yaml';
        file_put_contents($this->policyFile, "policy:\n  name: rest\n  review_required:\n    - \"risk:high\"\n");

        $categories = new CategoryRegistry();
        $categories->register(new AbilityCategory('testing', 'Testing'));
        $registry = new AbilitiesRegistry([
            new EchoAbility(),
            new HiddenAbility(),
            new WriteAbility(),
            new CallbackAbility(static fn(): mixed => 'destroyed'),
        ]);
        $this->handler = new RestRequestHandler(
            $registry,
            new AbilityExecutor(new SchemaValidator(), new PolicyProvider($this->policyFile)),
            $categories,
            new CapabilityCatalog([new AbilitiesSource($registry, new RestConfiguration())]),
            new RestResponseFactory(),
            new RestInputMapper(),
        );
        $this->router = new RestRouter();
    }

    protected function tearDown(): void
    {
        @unlink($this->policyFile);
    }

    /**
     * @param list<string> $scopes
     */
    private function call(string $method, string $uri, ?string $body = null, array $scopes = ['*']): ResponseInterface
    {
        $request = new ServerRequest($uri, $method);
        parse_str((string)parse_url($uri, PHP_URL_QUERY), $query);
        $request = $request->withQueryParams($query);
        if ($body !== null) {
            $stream = new Stream('php://temp', 'rw');
            $stream->write($body);
            $stream->rewind();
            $request = $request->withBody($stream);
        }
        $route = $this->router->match((string)parse_url($uri, PHP_URL_PATH), '/abilities/v1');
        self::assertInstanceOf(RestRoute::class, $route, 'route must match: ' . $uri);

        return $this->handler->handle($route, $request, ExecutionContext::rest($scopes, 3));
    }

    #[Test]
    public function listsRestExposedAbilitiesWithPaginationHeaders(): void
    {
        $response = $this->call('GET', 'http://localhost/abilities/v1/abilities?per_page=2&page=2');

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('3', $response->getHeaderLine('X-Total'));
        self::assertSame('2', $response->getHeaderLine('X-Total-Pages'));
        $data = self::asArray(self::decodeJson((string)$response->getBody())['data']);
        self::assertSame(3, $data['total']);
        self::assertSame(2, $data['page']);
        $abilities = self::asArray($data['abilities']);
        self::assertCount(1, $abilities);
        self::assertSame('test/write', self::asArray($abilities[0])['name']);
        self::assertSame('POST', self::asArray($abilities[0])['restMethod']);
    }

    #[Test]
    public function listFiltersByCategory(): void
    {
        $response = $this->call('GET', 'http://localhost/abilities/v1/abilities?category=other');

        self::assertSame('0', $response->getHeaderLine('X-Total'));
        self::assertSame(0, self::asArray(self::decodeJson((string)$response->getBody())['data'])['total']);
    }

    #[Test]
    public function describesExposedAbilitiesAndHidesOthers(): void
    {
        $ok = $this->call('GET', 'http://localhost/abilities/v1/abilities/test/echo');
        self::assertSame(200, $ok->getStatusCode());
        $data = self::asArray(self::decodeJson((string)$ok->getBody())['data']);
        self::assertSame('test/echo', $data['name']);
        self::assertSame('object', self::asArray($data['inputSchema'])['type']);

        $hidden = $this->call('GET', 'http://localhost/abilities/v1/abilities/test/hidden');
        self::assertSame(404, $hidden->getStatusCode());
        self::assertSame('rest_ability_not_found', self::decodeJson((string)$hidden->getBody())['code']);

        self::assertSame(404, $this->call('GET', 'http://localhost/abilities/v1/abilities/nope/nope')->getStatusCode());
    }

    #[Test]
    public function readOnlyAbilitiesRunViaGetWithQueryInput(): void
    {
        $response = $this->call('GET', 'http://localhost/abilities/v1/abilities/test/echo/run?message=hi&repeat=2');

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['ok' => true, 'data' => ['echo' => 'hihi']], self::decodeJson((string)$response->getBody()));
    }

    #[Test]
    public function wrongMethodIs405WithAllowHeader(): void
    {
        $post = $this->call('POST', 'http://localhost/abilities/v1/abilities/test/echo/run', '{"input":{"message":"hi"}}');
        self::assertSame(405, $post->getStatusCode());
        self::assertSame('GET', $post->getHeaderLine('Allow'));
        self::assertSame('rest_ability_invalid_method', self::decodeJson((string)$post->getBody())['code']);

        $get = $this->call('GET', 'http://localhost/abilities/v1/abilities/test/write/run?value=x');
        self::assertSame(405, $get->getStatusCode());
        self::assertSame('POST', $get->getHeaderLine('Allow'));

        $delete = $this->call('POST', 'http://localhost/abilities/v1/abilities/test/callback/run', '{}');
        self::assertSame('DELETE', $delete->getHeaderLine('Allow'));

        $list = $this->call('DELETE', 'http://localhost/abilities/v1/abilities');
        self::assertSame(405, $list->getStatusCode());
        self::assertSame('rest_invalid_method', self::decodeJson((string)$list->getBody())['code']);
    }

    #[Test]
    public function writesRunViaPostBodyAndScopesAreEnforced(): void
    {
        $ok = $this->call('POST', 'http://localhost/abilities/v1/abilities/test/write/run', '{"input":{"value":"v"}}', ['testing:write']);
        self::assertSame(200, $ok->getStatusCode());
        self::assertSame(['stored' => 'v'], self::asArray(self::decodeJson((string)$ok->getBody())['data']));

        $forbidden = $this->call('POST', 'http://localhost/abilities/v1/abilities/test/write/run', '{"input":{"value":"v"}}', ['testing:read']);
        self::assertSame(403, $forbidden->getStatusCode());
        self::assertSame('ability_invalid_permissions', self::decodeJson((string)$forbidden->getBody())['code']);

        $invalid = $this->call('POST', 'http://localhost/abilities/v1/abilities/test/write/run', '{"input":{}}', ['testing:write']);
        self::assertSame(400, $invalid->getStatusCode());
        self::assertSame('ability_invalid_input', self::decodeJson((string)$invalid->getBody())['code']);

        $malformed = $this->call('POST', 'http://localhost/abilities/v1/abilities/test/write/run', 'nope', ['testing:write']);
        self::assertSame(400, $malformed->getStatusCode());
        self::assertSame('ability_invalid_input', self::decodeJson((string)$malformed->getBody())['code']);
    }

    #[Test]
    public function reviewRequiredIs409OverRest(): void
    {
        $response = $this->call('DELETE', 'http://localhost/abilities/v1/abilities/test/callback/run', '{}', ['testing:write']);

        self::assertSame(409, $response->getStatusCode());
        self::assertSame('ability_review_required', self::decodeJson((string)$response->getBody())['code']);
    }

    #[Test]
    public function catalogEndpointListsEverySourceAndFilters(): void
    {
        $all = $this->call('GET', 'http://localhost/abilities/v1/catalog');
        self::assertSame(200, $all->getStatusCode());
        $data = self::asArray(self::decodeJson((string)$all->getBody())['data']);
        self::assertSame(4, $data['total'], 'the hidden ability is catalogued too — the catalogue is the whole registry');
        self::assertSame(['abilities' => 4], $data['sources']);
        $first = self::asArray(self::asArray($data['entries'])[0]);
        self::assertSame('test/callback', $first['id']);
        self::assertSame('abilities', $first['source']);
        self::assertSame(['readonly' => false, 'destructive' => true, 'idempotent' => false], $first['annotations']);
        self::assertSame('DELETE /abilities/v1/abilities/test/callback/run', self::asArray($first['invocations'])['rest']);

        $filtered = $this->call('GET', 'http://localhost/abilities/v1/catalog?surface=mcp&search=echo');
        $entries = self::asArray(self::asArray(self::decodeJson((string)$filtered->getBody())['data'])['entries']);
        self::assertSame(['test/echo'], array_map(static fn(mixed $entry): mixed => self::asArray($entry)['id'], $entries));

        self::assertSame(405, $this->call('POST', 'http://localhost/abilities/v1/catalog', '{}')->getStatusCode());
    }

    #[Test]
    public function categoriesEndpoints(): void
    {
        $all = $this->call('GET', 'http://localhost/abilities/v1/categories');
        self::assertSame(200, $all->getStatusCode());
        $data = self::asArray(self::decodeJson((string)$all->getBody())['data']);
        $slugs = array_map(static fn(mixed $category): mixed => self::asArray($category)['slug'], self::asArray($data['categories']));
        self::assertContains('testing', $slugs);
        self::assertContains('system', $slugs);

        $one = $this->call('GET', 'http://localhost/abilities/v1/categories/testing');
        $category = self::asArray(self::decodeJson((string)$one->getBody())['data']);
        self::assertSame('Testing', $category['label']);
        self::assertSame(['test/callback', 'test/echo', 'test/write'], $category['abilities'], 'hidden ability is not REST-exposed');

        $missing = $this->call('GET', 'http://localhost/abilities/v1/categories/nope');
        self::assertSame(404, $missing->getStatusCode());
        self::assertSame('rest_ability_category_not_found', self::decodeJson((string)$missing->getBody())['code']);
    }
}
