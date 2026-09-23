<?php

declare(strict_types=1);

namespace Webconsulting\Abilities\Tests\Functional\Backend;

use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;
use Webconsulting\Abilities\Backend\Controller\AbilitiesAjaxController;
use Webconsulting\Abilities\Security\TokenService;
use Webconsulting\Abilities\Tests\Support\TypeNarrowing;

/**
 * The endpoints behind the backend module's tabs: the registry with the
 * policy decision the Run tab needs for its review checkbox, governed runs
 * reporting their trace uid, the trace listing with its filters, and the
 * token lifecycle (create → list → revoke).
 */
final class AbilitiesAjaxControllerTest extends FunctionalTestCase
{
    use TypeNarrowing;

    protected array $testExtensionsToLoad = ['webconsulting/typo3-abilities'];

    private AbilitiesAjaxController $controller;

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/be_users.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/be_groups.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/pages.csv');

        GeneralUtility::mkdir_deep(Environment::getProjectPath() . '/config');
        copy(
            __DIR__ . '/../../../Resources/Private/Examples/abilities-policy.yaml',
            Environment::getProjectPath() . '/config/abilities-policy.yaml',
        );

        $this->setUpBackendUser(1);

        $controller = $this->get(AbilitiesAjaxController::class);
        self::assertInstanceOf(AbilitiesAjaxController::class, $controller);
        $this->controller = $controller;
    }

    /**
     * @param array<string, string> $query
     */
    private function ajaxGet(string $method, array $query = []): ResponseInterface
    {
        return $this->controller->{$method}(new ServerRequest('https://localhost/typo3/ajax')->withQueryParams($query));
    }

    /**
     * @param array<string, mixed> $body
     */
    private function post(string $method, array $body): ResponseInterface
    {
        return $this->controller->{$method}(
            new ServerRequest('https://localhost/typo3/ajax', 'POST')->withParsedBody($body),
        );
    }

    /**
     * @return array<mixed>
     */
    private function json(ResponseInterface $response): array
    {
        return self::decodeJson((string)$response->getBody());
    }

    #[Test]
    public function listAndDescribeCarryThePolicyDecisionTheRunTabNeeds(): void
    {
        $list = $this->json($this->ajaxGet('list'));
        self::assertSame(8, $list['total']);

        $byName = [];
        foreach (self::asArray($list['abilities']) as $ability) {
            $ability = self::asArray($ability);
            $byName[self::asString($ability['name'])] = $ability;
        }

        // risk:low — allowed outright, no checkbox.
        self::assertSame(
            ['allowed' => true, 'reviewRequired' => false, 'reason' => null],
            self::asArray($byName['content/search'])['policy'],
        );

        // risk:high — the policy example wants a human, so the Run tab shows
        // the review checkbox with the policy's own reason.
        $delete = self::asArray(self::asArray($byName['content/delete-page'])['policy']);
        self::assertFalse($delete['allowed']);
        self::assertTrue($delete['reviewRequired']);
        self::assertStringContainsString('risk:high', self::asString($delete['reason']));

        $described = $this->json($this->ajaxGet('describe', ['name' => 'content/search']));
        self::assertSame('GET', $described['restMethod']);
        self::assertTrue(self::asArray(self::asArray($described['policy']))['allowed']);
        self::assertSame(['term'], self::asArray($described['inputSchema'])['required']);
        self::assertSame('object', self::asArray($described['outputSchema'])['type']);

        self::assertSame(404, $this->ajaxGet('describe', ['name' => 'nope/nope'])->getStatusCode());
    }

    #[Test]
    public function catalogueIsServedForTheModuleAndTheJsClient(): void
    {
        $all = $this->json($this->ajaxGet('catalog'));
        self::assertGreaterThan(8, $all['total']);
        self::assertSame(['abilities', 'cli', 'mcp', 'rest', 'skills'], array_keys(self::asArray($all['sources'])));

        $abilities = $this->json($this->ajaxGet('catalog', ['source' => 'abilities']));
        self::assertSame(8, $abilities['total']);

        $search = $this->json($this->ajaxGet('catalog', ['search' => 'delete-page', 'surface' => 'rest']));
        self::assertSame(['content/delete-page'], array_map(
            static fn(mixed $entry): mixed => self::asArray($entry)['id'],
            self::asArray($search['entries']),
        ));
        self::assertSame(0, $this->json($this->ajaxGet('catalog', ['search' => 'delete-page', 'surface' => 'scheduler']))['total']);
    }

    #[Test]
    public function runsExecuteAsTheBackendUserAndReportTheirTraceUid(): void
    {
        $response = $this->post('run', ['name' => 'content/search', 'input' => ['term' => 'roadmap']]);
        self::assertSame(200, $response->getStatusCode(), (string)$response->getBody());
        $body = $this->json($response);
        self::assertTrue($body['ok']);
        self::assertSame(1, self::asArray($body['data'])['total']);
        $traceUid = $body['traceUid'];
        self::assertIsInt($traceUid);

        $trace = $this->getConnectionPool()->getConnectionForTable('tx_abilities_trace')
            ->select(['*'], 'tx_abilities_trace', ['uid' => $traceUid])->fetchAssociative();
        self::assertIsArray($trace);
        self::assertSame('backend', $trace['surface']);
        self::assertSame(1, (int)$trace['be_user'], 'the logged-in backend user is the acting identity');

        // Unapproved high-risk run: 409, nothing executed, still traced.
        $gated = $this->post('run', ['name' => 'content/delete-page', 'input' => ['uid' => 4]]);
        self::assertSame(409, $gated->getStatusCode());
        self::assertSame('ability_review_required', $this->json($gated)['errorCode']);

        // The module's review checkbox is the human in the loop.
        $approved = $this->post('run', [
            'name' => 'content/delete-page',
            'input' => ['uid' => 4],
            'approveReview' => true,
        ]);
        self::assertSame(200, $approved->getStatusCode(), (string)$approved->getBody());
        self::assertTrue(self::asArray($this->json($approved)['data'])['deleted']);

        // "false" from a form-encoded body must not read as approval.
        $stringFalse = $this->post('run', [
            'name' => 'content/delete-page',
            'input' => ['uid' => 3],
            'approveReview' => 'false',
        ]);
        self::assertSame(409, $stringFalse->getStatusCode());
    }

    #[Test]
    public function traceListingFiltersByAbilitySurfaceAndOutcome(): void
    {
        $this->post('run', ['name' => 'content/search', 'input' => ['term' => 'roadmap']]);
        $this->post('run', ['name' => 'content/search', 'input' => ['term' => 'x']]);
        $this->post('run', ['name' => 'content/delete-page', 'input' => ['uid' => 4]]);

        $all = $this->json($this->ajaxGet('traceList'));
        self::assertSame(3, $all['total']);
        self::assertSame(3, $all['totalStored']);
        self::assertSame(['backend'], $all['surfaces']);
        $newest = self::asArray(self::asArray($all['traces'])[0]);
        self::assertSame('content/delete-page', $newest['ability'], 'newest first');

        self::assertSame(2, $this->json($this->ajaxGet('traceList', ['ability' => 'content/search']))['total']);
        self::assertSame(1, $this->json($this->ajaxGet('traceList', ['ok' => '1']))['total']);
        self::assertSame(2, $this->json($this->ajaxGet('traceList', ['ok' => '0']))['total']);
        self::assertSame(0, $this->json($this->ajaxGet('traceList', ['surface' => 'rest']))['total']);
        self::assertCount(1, self::asArray($this->json($this->ajaxGet('traceList', ['limit' => '1']))['traces']));
    }

    #[Test]
    public function tokensAreCreatedListedAndRevoked(): void
    {
        $empty = $this->json($this->ajaxGet('tokens'));
        self::assertSame(0, $empty['total']);
        self::assertSame(
            ['abilities:read', 'content:read', 'pages:write', 'system:read', 'workspace:publish'],
            $empty['scopes'],
            'the form offers exactly the scopes the registry declares',
        );

        $created = $this->post('tokenCreate', [
            'name' => 'n8n production',
            'scopes' => ['content:read', 'pages:write'],
            'expiresInDays' => 30,
        ]);
        self::assertSame(201, $created->getStatusCode(), (string)$created->getBody());
        $issued = $this->json($created);
        $plaintext = self::asString($issued['token']);
        self::assertStringStartsWith('abl_', $plaintext);
        self::assertSame(['content:read', 'pages:write'], $issued['scopes']);
        self::assertSame(['content:read', 'pages:write'], $issued['effectiveScopes'], 'admin holds "*", so the token keeps its own scopes');
        self::assertGreaterThan(time(), self::asArray($issued)['expires']);

        $tokenService = $this->get(TokenService::class);
        self::assertInstanceOf(TokenService::class, $tokenService);
        self::assertNotNull($tokenService->authenticate($plaintext), 'the plaintext shown once actually works');

        $row = $this->getConnectionPool()->getConnectionForTable('tx_abilities_token')
            ->select(['*'], 'tx_abilities_token', ['uid' => $issued['uid']])->fetchAssociative();
        self::assertIsArray($row);
        self::assertSame(hash('sha256', $plaintext), $row['token_hash'], 'only the hash is stored');

        $listed = $this->json($this->ajaxGet('tokens'));
        self::assertSame(1, $listed['total']);
        self::assertStringNotContainsString($plaintext, (string)json_encode($listed), 'the plaintext is never listed again');

        self::assertSame(400, $this->post('tokenCreate', ['name' => '  '])->getStatusCode());

        self::assertSame(200, $this->post('tokenRevoke', ['uid' => $issued['uid']])->getStatusCode());
        self::assertNull($tokenService->authenticate($plaintext), 'a revoked token stops working immediately');
        self::assertSame(0, $this->json($this->ajaxGet('tokens'))['total']);
        self::assertSame(404, $this->post('tokenRevoke', ['uid' => $issued['uid']])->getStatusCode());
        self::assertSame(400, $this->post('tokenRevoke', ['uid' => 0])->getStatusCode());
    }
}
