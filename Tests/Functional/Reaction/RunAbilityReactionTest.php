<?php

declare(strict_types=1);

namespace Webconsulting\Abilities\Tests\Functional\Reaction;

use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Reactions\Model\ReactionInstruction;
use TYPO3\CMS\Reactions\ReactionRegistry;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;
use Webconsulting\Abilities\Catalog\AbilityCatalog;
use Webconsulting\Abilities\Catalog\CatalogEntry;
use Webconsulting\Abilities\Reaction\RunAbilityReaction;
use Webconsulting\Abilities\Tests\Support\TypeNarrowing;

/**
 * The webhook surface with EXT:reactions loaded: the reaction type is
 * registered in TCA and the reaction registry, runs act as the impersonated
 * backend user with that user's scopes, review-gated abilities answer 409
 * like REST, and every attempt is traced with surface "webhook".
 */
final class RunAbilityReactionTest extends FunctionalTestCase
{
    use TypeNarrowing;

    protected array $coreExtensionsToLoad = ['reactions'];

    protected array $testExtensionsToLoad = ['webconsulting/typo3-abilities'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/be_users.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/be_groups.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/pages.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/tt_content.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/sys_reaction.csv');

        GeneralUtility::mkdir_deep(Environment::getProjectPath() . '/config');
        copy(__DIR__ . '/../../../Resources/Private/Examples/abilities-policy.yaml', Environment::getProjectPath() . '/config/abilities-policy.yaml');

        // ReactionHandler boots the impersonated user into $GLOBALS['BE_USER'] before react(); the editor holds pages:write and content:read.
        $this->setUpBackendUser(2);
    }

    private function reaction(): RunAbilityReaction
    {
        $registry = $this->get(ReactionRegistry::class);
        self::assertInstanceOf(ReactionRegistry::class, $registry);
        $reaction = $registry->getReactionByType(RunAbilityReaction::TYPE);
        self::assertInstanceOf(RunAbilityReaction::class, $reaction);

        return $reaction;
    }

    /**
     * @param array<mixed> $payload
     */
    private function react(string $ability, array $payload): ResponseInterface
    {
        $instruction = new ReactionInstruction([
            'uid' => 1,
            'name' => 'Webhook',
            'reaction_type' => RunAbilityReaction::TYPE,
            'identifier' => 'test-hook',
            'impersonate_user' => 2,
            RunAbilityReaction::FIELD_ABILITY => $ability,
        ]);

        return $this->reaction()->react(new ServerRequest('https://localhost/typo3/reaction/test-hook', 'POST'), $payload, $instruction);
    }

    #[Test]
    public function reactionTypeIsRegisteredInTcaAndCatalogued(): void
    {
        self::assertArrayHasKey(RunAbilityReaction::TYPE, $GLOBALS['TCA']['sys_reaction']['types']);
        self::assertArrayHasKey(RunAbilityReaction::FIELD_ABILITY, $GLOBALS['TCA']['sys_reaction']['columns']);
        $types = array_column($GLOBALS['TCA']['sys_reaction']['columns']['reaction_type']['config']['items'], 'value');
        self::assertContains(RunAbilityReaction::TYPE, $types);

        $catalog = $this->get(AbilityCatalog::class);
        self::assertInstanceOf(AbilityCatalog::class, $catalog);
        $webhooks = $catalog->entries(CatalogEntry::SOURCE_REST, 'webhook');
        self::assertSame(['webhook/search-hook'], array_map(static fn(CatalogEntry $entry): string => $entry->id, $webhooks), 'the disabled reaction is not catalogued');
        $hook = $webhooks[0];
        self::assertSame('Search webhook', $hook->title);
        self::assertStringContainsString('POST /typo3/reaction/search-hook', $hook->invocations['webhook']);
        self::assertSame(['readonly' => true, 'destructive' => false, 'idempotent' => true], $hook->annotations, 'a "Run ability" reaction carries the ability\'s annotations');
        self::assertSame(['term'], $hook->inputSchema['required']);
        self::assertSame('content/search', $hook->meta['ability']);
    }

    #[Test]
    public function runsTheBoundAbilityAsTheImpersonatedUserAndTraces(): void
    {
        $response = $this->react('content/search', ['input' => ['term' => 'roadmap', 'tables' => ['pages']]]);

        self::assertSame(200, $response->getStatusCode(), (string)$response->getBody());
        $body = self::decodeJson((string)$response->getBody());
        self::assertTrue($body['ok']);
        self::assertSame(1, self::asArray($body['data'])['total']);

        $bare = $this->react('content/search', ['term' => 'roadmap']);
        self::assertSame(200, $bare->getStatusCode(), 'a bare JSON object is the input too');

        $trace = $this->getConnectionPool()->getConnectionForTable('tx_abilities_trace')
            ->select(['surface', 'be_user', 'ok'], 'tx_abilities_trace', ['ability' => 'content/search'])->fetchAssociative();
        self::assertSame(['surface' => 'webhook', 'be_user' => 2, 'ok' => 1], array_map(static fn(mixed $v): mixed => is_numeric($v) ? (int)$v : $v, (array)$trace));
    }

    #[Test]
    public function scopesOfTheImpersonatedUserApply(): void
    {
        // "reader" is in the Readers group only: news:read plus abilities:read from the subgroup, no content:read.
        $this->setUpBackendUser(4);

        $forbidden = $this->react('content/search', ['input' => ['term' => 'roadmap']]);

        self::assertSame(403, $forbidden->getStatusCode(), (string)$forbidden->getBody());
        $body = self::decodeJson((string)$forbidden->getBody());
        self::assertSame('ability_invalid_permissions', $body['code']);
        self::assertStringContainsString('content:read', self::asString($body['message']));

        $trace = $this->getConnectionPool()->getConnectionForTable('tx_abilities_trace')
            ->select(['error_code', 'be_user'], 'tx_abilities_trace', ['ability' => 'content/search'])->fetchAssociative();
        self::assertIsArray($trace);
        self::assertSame('ability_invalid_permissions', $trace['error_code']);
        self::assertSame(4, (int)$trace['be_user']);
    }

    #[Test]
    public function governsLikeRest(): void
    {
        $gated = $this->react('content/delete-page', ['input' => ['uid' => 4]]);
        self::assertSame(409, $gated->getStatusCode());
        self::assertSame('ability_review_required', self::decodeJson((string)$gated->getBody())['code']);
        self::assertIsArray(BackendUtility::getRecord('pages', 4), 'a webhook can never approve a review');

        $gatedBeforeScopes = $this->react('workspace/publish', ['input' => ['workspace' => 1]]);
        self::assertSame(409, $gatedBeforeScopes->getStatusCode(), 'the policy gate runs before the scope check');

        $invalid = $this->react('content/search', ['input' => ['term' => 'x']]);
        self::assertSame(400, $invalid->getStatusCode());
        self::assertSame('ability_invalid_input', self::decodeJson((string)$invalid->getBody())['code']);

        $malformed = $this->react('content/search', [1, 2, 3]);
        self::assertSame(400, $malformed->getStatusCode());

        $unbound = $this->react('', ['input' => []]);
        self::assertSame(404, $unbound->getStatusCode());
        self::assertSame('rest_ability_not_found', self::decodeJson((string)$unbound->getBody())['code']);
    }
}
