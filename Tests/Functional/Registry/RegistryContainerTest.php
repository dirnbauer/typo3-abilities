<?php

declare(strict_types=1);

namespace Webconsulting\Abilities\Tests\Functional\Registry;

use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;
use Webconsulting\Abilities\Backend\Tca\RegistryItemsProcFunc;
use Webconsulting\Abilities\Catalog\AbilityCatalog;
use Webconsulting\Abilities\Catalog\CatalogEntry;
use Webconsulting\Abilities\Category\CategoryRegistry;
use Webconsulting\Abilities\Domain\ExecutionContext;
use Webconsulting\Abilities\Execution\AbilityExecutor;
use Webconsulting\Abilities\Projection\Mcp\McpProjection;
use Webconsulting\Abilities\Projection\Mcp\McpToolDescriptor;
use Webconsulting\Abilities\Registry\AbilitiesRegistry;
use Webconsulting\Abilities\Skills\SkillAbilityContract;
use Webconsulting\Abilities\Tests\Support\TypeNarrowing;

/**
 * The registry as the DI container builds it: every #[AsAbility] service is
 * collected, the registry abilities resolve their service-closure
 * dependency, and the projections see the same entries.
 */
final class RegistryContainerTest extends FunctionalTestCase
{
    use TypeNarrowing;

    protected array $testExtensionsToLoad = ['webconsulting/typo3-abilities'];

    #[Test]
    public function containerCollectsTheShippedAbilities(): void
    {
        $registry = $this->get(AbilitiesRegistry::class);
        self::assertInstanceOf(AbilitiesRegistry::class, $registry);

        self::assertSame(
            ['abilities/catalog', 'abilities/describe', 'abilities/list', 'content/create-page-draft', 'content/delete-page', 'content/search', 'system/site-info', 'workspace/publish'],
            $registry->getNames(),
        );
        self::assertSame('registry', $registry->getDefinition('abilities/list')->category);
        self::assertTrue($registry->getDefinition('abilities/list')->isReadOnly());
        self::assertSame(['abilities:read', 'content:read', 'pages:write', 'system:read', 'workspace:publish'], $registry->getDeclaredScopes());

        $categories = $this->get(CategoryRegistry::class);
        self::assertInstanceOf(CategoryRegistry::class, $categories);
        foreach ($registry->getCategoriesInUse() as $slug) {
            self::assertTrue($categories->has($slug), $slug);
        }
    }

    #[Test]
    public function registryAbilitiesIntrospectTheRegistry(): void
    {
        $registry = $this->get(AbilitiesRegistry::class);
        $executor = $this->get(AbilityExecutor::class);
        self::assertInstanceOf(AbilitiesRegistry::class, $registry);
        self::assertInstanceOf(AbilityExecutor::class, $executor);

        $list = $executor->execute($registry->get('abilities/list'), ['category' => 'registry'], ExecutionContext::cli());
        self::assertTrue($list->ok, (string)$list->error);
        $data = self::asArray($list->data);
        self::assertSame(3, $data['total']);

        $describe = $executor->execute($registry->get('abilities/describe'), ['name' => 'system/site-info'], ExecutionContext::cli());
        self::assertTrue($describe->ok, (string)$describe->error);
        $described = self::asArray($describe->data);
        self::assertSame('ability_system_site-info', $described['mcpToolName']);
        self::assertSame('GET', $described['restMethod']);
        self::assertSame('object', self::asArray($described['outputSchema'])['type']);

        $unknown = $executor->execute($registry->get('abilities/describe'), ['name' => 'nope/nope'], ExecutionContext::cli());
        self::assertSame('ability_cannot_execute', $unknown->errorCode);
    }

    #[Test]
    public function mcpProjectionAndSkillContractSeeTheRegistry(): void
    {
        $projection = $this->get(McpProjection::class);
        self::assertInstanceOf(McpProjection::class, $projection);
        $names = array_map(static fn(McpToolDescriptor $descriptor): string => $descriptor->name, [...$projection->descriptors()]);
        self::assertSame(
            ['ability_abilities_catalog', 'ability_abilities_describe', 'ability_abilities_list', 'ability_content_create-page-draft', 'ability_content_delete-page', 'ability_content_search', 'ability_system_site-info', 'ability_workspace_publish'],
            $names,
        );

        $result = $projection->execute('ability_system_site-info', [], ExecutionContext::mcp());
        self::assertTrue($result->ok, (string)$result->error);
        self::assertIsString(self::asArray($result->data)['typo3Version']);

        $contract = $this->get(SkillAbilityContract::class);
        self::assertInstanceOf(SkillAbilityContract::class, $contract);
        self::assertSame(['system/site-info' => 'mcp__typo3__ability_system_site-info'], $contract->resolveMcpToolNames(['system/site-info']));
        self::assertSame([], $contract->validate(['system/site-info', 'abilities/list']));
        self::assertSame('missing', $contract->validate(['nope/nope'])[0]['code']);
    }

    #[Test]
    public function itemsProcFuncsOfferDeclaredScopesAndAbilities(): void
    {
        $procFunc = $this->get(RegistryItemsProcFunc::class);
        self::assertInstanceOf(RegistryItemsProcFunc::class, $procFunc);

        $parameters = ['items' => [['label' => 'All scopes (*)', 'value' => '*']]];
        $procFunc->addAbilityScopes($parameters);

        self::assertSame(
            ['*', 'abilities:read', 'content:read', 'pages:write', 'system:read', 'workspace:publish'],
            array_map(static fn(mixed $item): mixed => self::asArray($item)['value'], self::asArray($parameters['items'])),
        );

        $abilities = ['items' => []];
        $procFunc->addAbilities($abilities);
        self::assertSame(
            $this->get(AbilitiesRegistry::class)?->getNames(),
            array_map(static fn(mixed $item): mixed => self::asArray($item)['value'], self::asArray($abilities['items'])),
            'every shipped ability is REST-exposed, so the webhook picker offers all of them',
        );
    }

    #[Test]
    public function catalogueUnitesEverySourceTheContainerKnows(): void
    {
        $catalog = $this->get(AbilityCatalog::class);
        self::assertInstanceOf(AbilityCatalog::class, $catalog);

        self::assertSame(['abilities', 'cli', 'mcp', 'rest', 'skills'], $catalog->sources());
        $byId = [];
        foreach ($catalog->entries() as $entry) {
            $byId[$entry->id] = $entry;
        }

        // The registry's abilities, with one invocation per exposed surface.
        self::assertArrayHasKey('abilities/catalog', $byId);
        self::assertSame(CatalogEntry::SOURCE_ABILITIES, $byId['content/search']->source);
        self::assertSame('ability_content_search', $byId['content/search']->invocations['mcp']);
        self::assertSame('GET /abilities/v1/abilities/content/search/run', $byId['content/search']->invocations['rest']);
        self::assertArrayHasKey('frontend', $byId['content/search']->invocations, 'read-only abilities render in Fluid');
        self::assertArrayNotHasKey('frontend', $byId['content/delete-page']->invocations);

        // Console commands with a schema derived from their input definition.
        self::assertArrayHasKey('cli/abilities:run', $byId);
        $run = $byId['cli/abilities:run'];
        self::assertSame(['cli', 'scheduler'], $run->surfaces, 'abilities:run is schedulable');
        self::assertSame(['name'], $run->inputSchema['required']);
        self::assertArrayHasKey('--approve-review', self::asArray($run->inputSchema['properties']));
        self::assertSame('vendor/bin/typo3 abilities:run', $run->invocations['cli']);

        // The REST projection's own endpoints.
        self::assertSame('GET /abilities/v1/catalog', $byId['rest/catalog']->invocations['rest']);
        self::assertSame('GET|POST|DELETE /abilities/v1/abilities/{namespace}/{name}/run', $byId['rest/abilities-run']->invocations['rest']);

        // Optional sources yield nothing when their extension is absent.
        self::assertSame([], $catalog->entries(CatalogEntry::SOURCE_MCP));
        self::assertSame([], $catalog->entries(CatalogEntry::SOURCE_SKILLS));

        $result = $this->get(AbilityExecutor::class)?->execute(
            $this->get(AbilitiesRegistry::class)?->get('abilities/catalog') ?? throw new \LogicException('registry'),
            ['source' => 'rest', 'search' => 'catalog'],
            ExecutionContext::mcp(),
        );
        self::assertNotNull($result);
        self::assertTrue($result->ok, (string)$result->error);
        $data = self::asArray($result->data);
        self::assertSame(1, $data['total']);
        self::assertSame('rest/catalog', self::asArray(self::asArray($data['entries'])[0])['id']);
    }
}
