<?php

declare(strict_types=1);

namespace Webconsulting\Abilities\Tests\Unit\Catalog;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputOption;
use Webconsulting\Abilities\Catalog\CatalogEntry;
use Webconsulting\Abilities\Catalog\Source\AbilitiesSource;
use Webconsulting\Abilities\Catalog\Source\CliCommandSource;
use Webconsulting\Abilities\Catalog\Source\McpToolSource;
use Webconsulting\Abilities\Catalog\Source\RestEndpointSource;
use Webconsulting\Abilities\Catalog\Source\SkillSource;
use Webconsulting\Abilities\Domain\AbilityDefinition;
use Webconsulting\Abilities\Execution\AbilityExecutor;
use Webconsulting\Abilities\Http\RestConfiguration;
use Webconsulting\Abilities\Policy\PolicyProvider;
use Webconsulting\Abilities\Projection\Mcp\McpProjection;
use Webconsulting\Abilities\Registry\AbilitiesRegistry;
use Webconsulting\Abilities\Tests\Fixtures\CallbackAbility;
use Webconsulting\Abilities\Tests\Fixtures\EchoAbility;
use Webconsulting\Abilities\Tests\Fixtures\HiddenAbility;
use Webconsulting\Abilities\Validation\SchemaValidator;

/**
 * The pure mapping of each catalogue source: a registry entry, an MCP tool
 * schema, a console InputDefinition, a skill row and an sg-apicore endpoint
 * become CatalogEntry objects. The container-backed collection is
 * covered by the functional RegistryContainerTest.
 */
final class CatalogSourcesTest extends TestCase
{
    #[Test]
    public function abilitiesSourceDerivesOneInvocationPerExposedSurface(): void
    {
        $registry = new AbilitiesRegistry([new EchoAbility(), new HiddenAbility(), new CallbackAbility(static fn(): mixed => null)]);
        $entries = [...(new AbilitiesSource($registry, new RestConfiguration(basePath: '/api/abilities')))->getEntries()];

        self::assertSame(['test/callback', 'test/echo', 'test/hidden'], array_map(static fn(CatalogEntry $e): string => $e->id, $entries));

        $echo = $entries[1];
        self::assertSame(CatalogEntry::SOURCE_ABILITIES, $echo->source);
        self::assertSame(['cli', 'mcp', 'rest', 'webhook', 'php', 'frontend'], $echo->surfaces);
        self::assertSame("vendor/bin/typo3 abilities:run test/echo --input '{\"message\":\"…\"}'", $echo->invocations['cli']);
        self::assertSame('ability_test_echo', $echo->invocations['mcp']);
        self::assertSame('GET /api/abilities/abilities/test/echo/run', $echo->invocations['rest']);
        self::assertStringContainsString("get('test/echo')", $echo->invocations['php']);
        self::assertSame(['readonly' => true, 'destructive' => false, 'idempotent' => true], $echo->annotations);
        self::assertSame('object', $echo->inputSchema['type']);
        self::assertSame(['category' => 'testing', 'scopes' => ['testing:read'], 'riskTier' => 'low', 'sideEffects' => [], 'className' => EchoAbility::class], $echo->meta);

        $hidden = $entries[2];
        self::assertSame(['cli', 'php', 'frontend'], $hidden->surfaces, 'not exposed to mcp/rest');
        self::assertSame("vendor/bin/typo3 abilities:run test/hidden --input '{}'", $hidden->invocations['cli']);

        $destructive = $entries[0];
        self::assertSame('DELETE /api/abilities/abilities/test/callback/run', $destructive->invocations['rest']);
        self::assertArrayNotHasKey('frontend', $destructive->invocations, 'only read-only abilities render in Fluid');

        $restOff = AbilitiesSource::entry(AbilityDefinition::fromClassName(EchoAbility::class), [], new RestConfiguration(enabled: false));
        self::assertArrayNotHasKey('rest', $restOff->invocations);
        self::assertArrayNotHasKey('webhook', $restOff->invocations);
    }

    #[Test]
    public function cliSourceBuildsASchemaFromTheInputDefinition(): void
    {
        $definition = new InputDefinition([
            new InputArgument('name', InputArgument::REQUIRED, 'Ability name'),
            new InputArgument('extra', InputArgument::OPTIONAL | InputArgument::IS_ARRAY, 'More'),
            new InputOption('input', 'i', InputOption::VALUE_REQUIRED, 'JSON', '{}'),
            new InputOption('approve-review', null, InputOption::VALUE_NONE, 'Approve'),
        ]);

        $entry = CliCommandSource::entry('abilities:run', 'Run an ability', true, $definition);

        self::assertSame('cli/abilities:run', $entry->id);
        self::assertSame(CatalogEntry::SOURCE_CLI, $entry->source);
        self::assertSame(['cli', 'scheduler'], $entry->surfaces);
        self::assertSame('vendor/bin/typo3 abilities:run', $entry->invocations['cli']);
        self::assertStringContainsString('Execute console command', $entry->invocations['scheduler']);
        self::assertSame(['name'], $entry->inputSchema['required']);
        self::assertSame(
            [
                'name' => ['type' => 'string', 'description' => 'Ability name'],
                'extra' => ['type' => 'array', 'description' => 'More'],
                '--input' => ['type' => 'string', 'description' => 'JSON', 'default' => '{}'],
                '--approve-review' => ['type' => 'boolean', 'description' => 'Approve'],
            ],
            $entry->inputSchema['properties'],
        );

        $unschedulable = CliCommandSource::entry('cache:flush', '', false, null);
        self::assertSame(['cli'], $unschedulable->surfaces);
        self::assertSame([], $unschedulable->inputSchema, 'no definition, no schema');
    }

    #[Test]
    public function mcpSourceMapsToolSchemasAndYieldsNothingWithoutTheServer(): void
    {
        $entry = McpToolSource::entry('GetPage', [
            'description' => 'Reads one page record.',
            'inputSchema' => ['type' => 'object', 'properties' => ['uid' => ['type' => 'integer']], 'required' => ['uid']],
            'annotations' => ['title' => 'Get page', 'readOnlyHint' => true, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false],
        ]);

        self::assertSame('mcp/GetPage', $entry->id);
        self::assertSame('Get page', $entry->title);
        self::assertSame(CatalogEntry::SOURCE_MCP, $entry->source);
        self::assertSame(['mcp'], $entry->surfaces);
        self::assertSame(['mcp' => 'GetPage'], $entry->invocations);
        self::assertSame(['readonly' => true, 'destructive' => false, 'idempotent' => true], $entry->annotations);
        self::assertSame(['uid'], $entry->inputSchema['required']);
        self::assertSame(['openWorld' => false], $entry->meta);

        $bare = McpToolSource::entry('WriteTable', []);
        self::assertSame('WriteTable', $bare->title, 'falls back to the tool name');
        self::assertSame(['readonly' => false, 'destructive' => false, 'idempotent' => false], $bare->annotations);

        $registry = new AbilitiesRegistry([new EchoAbility()]);
        $projection = new McpProjection($registry, new AbilityExecutor(new SchemaValidator(), new PolicyProvider('/nonexistent/policy.yaml')));
        self::assertSame([], [...(new McpToolSource($projection))->getEntries()], 'hn/typo3-mcp-server is not installed here');
        self::assertSame(CatalogEntry::SOURCE_MCP, (new McpToolSource($projection))->getSource());
    }

    #[Test]
    public function skillSourceReadsFrontMatterOfBothSkillStores(): void
    {
        $nrLlm = SkillSource::entry('tx_nrllm_skill', [
            'uid' => 7,
            'name' => 'publish-editorial-drafts',
            'description' => 'fallback',
            'raw_frontmatter' => json_encode(['name' => 'publish-editorial-drafts', 'description' => 'Reviews drafts with a human.', 'abilities' => ['workspace/publish', 'content/search']]),
            'allowed_tools' => '["mcp__typo3__ability_workspace_publish","Bash(git:*)"]',
        ]);
        self::assertNotNull($nrLlm);
        self::assertSame('skill/publish-editorial-drafts', $nrLlm->id);
        self::assertSame('Reviews drafts with a human.', $nrLlm->description, 'front matter wins over the column');
        self::assertSame(CatalogEntry::SOURCE_SKILLS, $nrLlm->source);
        self::assertSame(['skills'], $nrLlm->surfaces);
        self::assertStringContainsString('workspace/publish, content/search', $nrLlm->invocations['skills']);
        self::assertSame(
            ['table' => 'tx_nrllm_skill', 'uid' => 7, 'abilities' => ['workspace/publish', 'content/search'], 'allowedTools' => ['mcp__typo3__ability_workspace_publish', 'Bash(git:*)']],
            $nrLlm->meta,
        );

        $skillflow = SkillSource::entry('tx_skillflow_skill', [
            'uid' => 141,
            'identifier' => 'abilities-demo',
            'title' => 'Abilities demo',
            'description' => 'Demonstrates a skill acting through the abilities registry.',
            'metadata' => json_encode(json_encode(['metadata' => ['category' => 'demo']])),
            'allowed_tools' => 'mcp__typo3__ability_system_site-info, mcp__typo3__ability_content_search',
        ]);
        self::assertNotNull($skillflow);
        self::assertSame('skill/abilities-demo', $skillflow->id);
        self::assertSame('Abilities demo', $skillflow->title);
        self::assertSame('Demonstrates a skill acting through the abilities registry.', $skillflow->description);
        self::assertSame(['mcp__typo3__ability_system_site-info', 'mcp__typo3__ability_content_search'], $skillflow->meta['allowedTools'], 'double-encoded metadata and comma lists are handled');
        self::assertArrayNotHasKey('abilities', $skillflow->meta);

        self::assertNull(SkillSource::entry('tx_nrllm_skill', ['uid' => 1, 'name' => '']));
    }

    #[Test]
    public function restSourceMapsSgApicoreEndpointsAndReactions(): void
    {
        $entry = RestEndpointSource::apiCoreEntry([
            'apiId' => ['shop'],
            'version' => ['v1'],
            'path' => '/orders/{uid}',
            'methods' => ['GET', 'DELETE'],
            'summary' => 'Order',
            'description' => 'Reads or cancels an order.',
            'authMode' => ['token'],
            'scopes' => ['orders:write'],
            'pathParams' => [(object)['name' => 'uid', 'type' => 'integer', 'description' => 'Order uid']],
            'queryParams' => [['name' => 'expand', 'type' => 'string', 'required' => false]],
            'bodyParams' => [(object)['name' => 'reason', 'type' => 'string', 'required' => true, 'description' => 'Why']],
        ], '/api/');

        self::assertSame('api/shop-orders-uid', $entry->id);
        self::assertSame('Order', $entry->title);
        self::assertSame(['rest' => 'GET|DELETE /api/shop/v1/orders/{uid}'], $entry->invocations);
        self::assertSame(['readonly' => false, 'destructive' => true, 'idempotent' => false], $entry->annotations);
        self::assertSame(['uid', 'reason'], $entry->inputSchema['required']);
        self::assertSame(['type' => 'integer', 'description' => 'Order uid'], $entry->inputSchema['properties']['uid']);
        self::assertSame(['type' => 'string'], $entry->inputSchema['properties']['expand']);
        self::assertSame('sg-apicore', $entry->meta['provider']);

        $minimal = RestEndpointSource::apiCoreEntry(['path' => '/health', 'methods' => ['GET']]);
        self::assertSame('api/health', $minimal->id);
        self::assertSame('GET /api/health', $minimal->invocations['rest']);
        self::assertTrue($minimal->annotations['readonly']);
        self::assertSame([], $minimal->inputSchema);
    }
}
