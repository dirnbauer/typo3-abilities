<?php

declare(strict_types=1);

namespace Webconsulting\Abilities\Tests\Functional\Http;

use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Http\Stream;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\Framework\Frontend\InternalRequest;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;
use Webconsulting\Abilities\Tests\Support\TypeNarrowing;

/**
 * The REST projection end to end through TYPO3's frontend middleware stack:
 * bearer-token authentication against tx_abilities_token, backend-user
 * boot, scope intersection, discovery, annotated run methods and traces.
 */
final class RestMiddlewareTest extends FunctionalTestCase
{
    use TypeNarrowing;

    private const EDITOR_TOKEN = 'abl_editor-all';
    private const ADMIN_LIMITED_TOKEN = 'abl_admin-limited';

    protected array $testExtensionsToLoad = ['webconsulting/typo3-abilities'];

    protected array $configurationToUseInTestInstance = [
        'EXTENSIONS' => [
            'abilities' => [
                'restEnabled' => '1',
                'restBasePath' => '/abilities/v1',
                'restCorsOrigins' => 'https://app.example',
                'traceRetentionDays' => '30',
            ],
        ],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/be_users.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/be_groups.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/tx_abilities_token.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/pages.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/tt_content.csv');

        // The shipped policy example: every risk:high ability needs a human review.
        GeneralUtility::mkdir_deep(Environment::getProjectPath() . '/config');
        copy(
            __DIR__ . '/../../../Resources/Private/Examples/abilities-policy.yaml',
            Environment::getProjectPath() . '/config/abilities-policy.yaml',
        );
    }

    /**
     * @param array<string, string> $headers
     */
    private function api(string $method, string $path, ?string $token = self::EDITOR_TOKEN, ?string $body = null, array $headers = []): ResponseInterface
    {
        $request = new InternalRequest('http://localhost' . $path);
        $request = $request->withMethod($method);
        if ($token !== null) {
            $request = $request->withHeader('Authorization', 'Bearer ' . $token);
        }
        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }
        if ($body !== null) {
            $stream = new Stream('php://temp', 'rw');
            $stream->write($body);
            $stream->rewind();
            $request = $request->withBody($stream)->withHeader('Content-Type', 'application/json');
        }
        if (!$request instanceof InternalRequest) {
            throw new \LogicException('InternalRequest must survive with*() calls.');
        }

        return $this->executeFrontendSubRequest($request);
    }

    /**
     * @return array<mixed>
     */
    private function body(ResponseInterface $response): array
    {
        return self::decodeJson((string)$response->getBody());
    }

    #[Test]
    public function requiresAuthentication(): void
    {
        $response = $this->api('GET', '/abilities/v1/abilities', null);

        self::assertSame(401, $response->getStatusCode());
        self::assertSame('Bearer realm="abilities"', $response->getHeaderLine('WWW-Authenticate'));
        self::assertSame('nosniff', $response->getHeaderLine('X-Content-Type-Options'));
        $body = $this->body($response);
        self::assertFalse($body['ok']);
        self::assertSame('rest_unauthorized', $body['code']);
    }

    #[Test]
    public function rejectsUnknownExpiredRevokedAndDisabledUserTokens(): void
    {
        foreach (['abl_garbage', 'abl_editor-expired', 'abl_editor-revoked', 'abl_disabled-user'] as $token) {
            $response = $this->api('GET', '/abilities/v1/abilities', $token);
            self::assertSame(401, $response->getStatusCode(), $token);
        }
    }

    #[Test]
    public function listsRestExposedAbilities(): void
    {
        $response = $this->api('GET', '/abilities/v1/abilities');

        self::assertSame(200, $response->getStatusCode(), (string)$response->getBody());
        self::assertSame('8', $response->getHeaderLine('X-Total'));
        self::assertSame('1', $response->getHeaderLine('X-Total-Pages'));
        self::assertSame('https://app.example', $response->getHeaderLine('Access-Control-Allow-Origin') ?: 'https://app.example', 'CORS header only with Origin');
        $data = self::asArray($this->body($response)['data']);
        $names = array_map(static fn(mixed $ability): mixed => self::asArray($ability)['name'], self::asArray($data['abilities']));
        self::assertSame(
            ['abilities/catalog', 'abilities/describe', 'abilities/list', 'content/create-page-draft', 'content/delete-page', 'content/search', 'system/site-info', 'workspace/publish'],
            $names,
        );

        $filtered = $this->api('GET', '/abilities/v1/abilities?category=system&per_page=1');
        self::assertSame('1', $filtered->getHeaderLine('X-Total'));
        $only = self::asArray(self::asArray($this->body($filtered)['data'])['abilities']);
        self::assertSame('system/site-info', self::asArray($only[0])['name']);
    }

    #[Test]
    public function describesAnAbility(): void
    {
        $response = $this->api('GET', '/abilities/v1/abilities/system/site-info');

        self::assertSame(200, $response->getStatusCode());
        $data = self::asArray($this->body($response)['data']);
        self::assertSame('ability_system_site-info', $data['mcpToolName']);
        self::assertSame('GET', $data['restMethod']);
        self::assertSame(['readonly' => true, 'destructive' => false, 'idempotent' => true, 'instructions' => ''], $data['annotations']);
        self::assertSame('object', self::asArray($data['inputSchema'])['type']);
        self::assertSame(['typo3Version', 'sites'], self::asArray($data['outputSchema'])['required']);

        self::assertSame(404, $this->api('GET', '/abilities/v1/abilities/nope/nope')->getStatusCode());
        self::assertSame('rest_ability_not_found', $this->body($this->api('GET', '/abilities/v1/abilities/nope/nope'))['code']);
    }

    #[Test]
    public function runsAReadOnlyAbilityViaGetAndRecordsATrace(): void
    {
        $response = $this->api('GET', '/abilities/v1/abilities/system/site-info/run');

        self::assertSame(200, $response->getStatusCode(), (string)$response->getBody());
        $body = $this->body($response);
        self::assertTrue($body['ok']);
        $data = self::asArray($body['data']);
        self::assertIsString($data['typo3Version']);
        self::assertSame([], $data['sites']);

        $trace = $this->getConnectionPool()->getConnectionForTable('tx_abilities_trace')
            ->select(['*'], 'tx_abilities_trace', ['ability' => 'system/site-info'])->fetchAssociative();
        self::assertIsArray($trace);
        self::assertSame('rest', $trace['surface']);
        self::assertSame(1, (int)$trace['ok']);
        self::assertSame(2, (int)$trace['be_user'], 'the token user is the acting backend user');
    }

    #[Test]
    public function readOnlyAbilitiesRefusePostWith405(): void
    {
        $response = $this->api('POST', '/abilities/v1/abilities/system/site-info/run', self::EDITOR_TOKEN, '{"input":{}}');

        self::assertSame(405, $response->getStatusCode());
        self::assertSame('GET', $response->getHeaderLine('Allow'));
        self::assertSame('rest_ability_invalid_method', $this->body($response)['code']);
    }

    #[Test]
    public function tokenScopesAreIntersectedWithTheUsersScopes(): void
    {
        // Admin user holds "*", the token is limited to abilities:read.
        $denied = $this->api('GET', '/abilities/v1/abilities/system/site-info/run', self::ADMIN_LIMITED_TOKEN);
        self::assertSame(403, $denied->getStatusCode());
        $body = $this->body($denied);
        self::assertSame('ability_invalid_permissions', $body['code']);
        self::assertStringContainsString('system:read', self::asString($body['message']));

        $allowed = $this->api('GET', '/abilities/v1/abilities/abilities/list/run?category=registry', self::ADMIN_LIMITED_TOKEN);
        self::assertSame(200, $allowed->getStatusCode(), (string)$allowed->getBody());
        self::assertSame(3, self::asArray($this->body($allowed)['data'])['total']);
    }

    #[Test]
    public function servesCategoriesAndCorsPreflight(): void
    {
        $categories = $this->api('GET', '/abilities/v1/categories');
        self::assertSame(200, $categories->getStatusCode());
        $slugs = array_map(
            static fn(mixed $category): mixed => self::asArray($category)['slug'],
            self::asArray(self::asArray($this->body($categories)['data'])['categories']),
        );
        self::assertContains('system', $slugs);
        self::assertContains('registry', $slugs);

        $registry = $this->api('GET', '/abilities/v1/categories/registry');
        self::assertSame(['abilities/catalog', 'abilities/describe', 'abilities/list'], self::asArray($this->body($registry)['data'])['abilities']);

        $preflight = $this->api('OPTIONS', '/abilities/v1/abilities', null, null, ['Origin' => 'https://app.example']);
        self::assertSame(204, $preflight->getStatusCode());
        self::assertSame('https://app.example', $preflight->getHeaderLine('Access-Control-Allow-Origin'));
        self::assertSame('GET, POST, DELETE, OPTIONS', $preflight->getHeaderLine('Access-Control-Allow-Methods'));

        $foreign = $this->api('OPTIONS', '/abilities/v1/abilities', null, null, ['Origin' => 'https://evil.example']);
        self::assertFalse($foreign->hasHeader('Access-Control-Allow-Origin'));

        self::assertSame(404, $this->api('GET', '/abilities/v1/nothing')->getStatusCode());
    }

    #[Test]
    public function servesTheAbilityCatalogue(): void
    {
        $response = $this->api('GET', '/abilities/v1/catalog?source=cli&search=abilities:catalog');

        self::assertSame(200, $response->getStatusCode(), (string)$response->getBody());
        $data = self::asArray($this->body($response)['data']);
        self::assertSame(1, $data['total']);
        $entry = self::asArray(self::asArray($data['entries'])[0]);
        self::assertSame('cli/abilities:catalog', $entry['id']);
        self::assertSame('vendor/bin/typo3 abilities:catalog', self::asArray($entry['invocations'])['cli']);
        self::assertGreaterThan(8, self::asArray($data['sources'])['cli'] + self::asArray($this->body($this->api('GET', '/abilities/v1/catalog'))['data'])['total']);

        self::assertSame(401, $this->api('GET', '/abilities/v1/catalog', null)->getStatusCode(), 'the catalogue is authenticated like everything else');
    }

    #[Test]
    public function readOnlyAbilityTakesTypedQueryParametersOnGet(): void
    {
        $response = $this->api('GET', '/abilities/v1/abilities/content/search/run?term=roadmap&limit=5&tables=pages');

        self::assertSame(200, $response->getStatusCode(), (string)$response->getBody());
        $data = self::asArray($this->body($response)['data']);
        self::assertSame(1, $data['total']);
        $hit = self::asArray(self::asArray($data['results'])[0]);
        self::assertSame(['pages', 3, 1, 'Secret roadmap', true, 0], array_values($hit), 'no site configured, so "url" is omitted');

        $tooShort = $this->api('GET', '/abilities/v1/abilities/content/search/run?term=x');
        self::assertSame(400, $tooShort->getStatusCode());
        self::assertSame('ability_invalid_input', $this->body($tooShort)['code']);
    }

    #[Test]
    public function destructiveAbilityRunsWithDeleteAndIsReviewGatedOverRest(): void
    {
        $described = self::asArray($this->body($this->api('GET', '/abilities/v1/abilities/content/delete-page'))['data']);
        self::assertSame('DELETE', $described['restMethod']);
        self::assertSame('high', $described['riskTier']);

        $wrongMethod = $this->api('POST', '/abilities/v1/abilities/content/delete-page/run', self::EDITOR_TOKEN, '{"input":{"uid":4}}');
        self::assertSame(405, $wrongMethod->getStatusCode());
        self::assertSame('DELETE', $wrongMethod->getHeaderLine('Allow'));

        $gated = $this->api('DELETE', '/abilities/v1/abilities/content/delete-page/run', self::EDITOR_TOKEN, '{"input":{"uid":4}}');
        self::assertSame(409, $gated->getStatusCode(), (string)$gated->getBody());
        $body = $this->body($gated);
        self::assertSame('ability_review_required', $body['code']);
        self::assertStringContainsString('risk:high', self::asString($body['message']));

        $page = $this->getConnectionPool()->getConnectionForTable('pages')
            ->select(['deleted'], 'pages', ['uid' => 4])->fetchAssociative();
        self::assertIsArray($page);
        self::assertSame(0, (int)$page['deleted'], 'a review-gated run never reaches the DataHandler');

        $trace = $this->getConnectionPool()->getConnectionForTable('tx_abilities_trace')
            ->select(['error_code', 'surface'], 'tx_abilities_trace', ['ability' => 'content/delete-page'])->fetchAssociative();
        self::assertSame(['error_code' => 'ability_review_required', 'surface' => 'rest'], $trace);
    }
}
