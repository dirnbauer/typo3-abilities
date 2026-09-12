<?php

declare(strict_types=1);

namespace Webconsulting\Abilities\Tests\Functional\Registry;

use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;
use Webconsulting\Abilities\Category\CategoryRegistry;
use Webconsulting\Abilities\Domain\ExecutionContext;
use Webconsulting\Abilities\Execution\AbilityExecutor;
use Webconsulting\Abilities\Permission\ScopeItemsProcFunc;
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

        self::assertSame(['abilities/describe', 'abilities/list', 'system/site-info'], $registry->getNames());
        self::assertSame('registry', $registry->getDefinition('abilities/list')->category);
        self::assertTrue($registry->getDefinition('abilities/list')->isReadOnly());
        self::assertSame(['abilities:read', 'system:read'], $registry->getDeclaredScopes());

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
        self::assertSame(2, $data['total']);

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
        self::assertSame(['ability_abilities_describe', 'ability_abilities_list', 'ability_system_site-info'], $names);

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
    public function scopeItemsProcFuncOffersDeclaredScopes(): void
    {
        $procFunc = $this->get(ScopeItemsProcFunc::class);
        self::assertInstanceOf(ScopeItemsProcFunc::class, $procFunc);

        $parameters = ['items' => [['label' => 'All scopes (*)', 'value' => '*']]];
        $procFunc->addAbilityScopes($parameters);

        self::assertSame(
            ['*', 'abilities:read', 'system:read'],
            array_map(static fn(mixed $item): mixed => self::asArray($item)['value'], self::asArray($parameters['items'])),
        );
    }
}
