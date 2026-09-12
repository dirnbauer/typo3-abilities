<?php

declare(strict_types=1);

namespace Webconsulting\Abilities\Tests\Unit\Ability;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Site\SiteFinder;
use Webconsulting\Abilities\Ability\Content\CreatePageDraftAbility;
use Webconsulting\Abilities\Ability\Content\DeletePageAbility;
use Webconsulting\Abilities\Ability\Content\SearchContentAbility;
use Webconsulting\Abilities\Ability\Workspace\PublishWorkspaceAbility;
use Webconsulting\Abilities\Domain\AbilityDefinition;
use Webconsulting\Abilities\Domain\ExecutionContext;
use Webconsulting\Abilities\Domain\RiskTier;
use Webconsulting\Abilities\Registry\AbilityInterface;
use Webconsulting\Abilities\Validation\SchemaValidator;

/**
 * The demo abilities' registry contracts are plain PHP: metadata,
 * annotations, projection facts and the JSON Schemas are pinned here;
 * executing them needs a booted TYPO3 (see Tests/Functional/Ability).
 */
final class DemoAbilitiesTest extends TestCase
{
    /**
     * @return iterable<string, array{class-string<AbilityInterface>, array<string, mixed>}>
     */
    public static function definitions(): iterable
    {
        yield 'content/search' => [SearchContentAbility::class, [
            'name' => 'content/search',
            'category' => 'content',
            'scopes' => ['content:read'],
            'riskTier' => RiskTier::Low,
            'readOnly' => true,
            'destructive' => false,
            'idempotent' => true,
            'restMethod' => 'GET',
            'mcpToolName' => 'ability_content_search',
        ]];
        yield 'content/create-page-draft' => [CreatePageDraftAbility::class, [
            'name' => 'content/create-page-draft',
            'category' => 'content',
            'scopes' => ['pages:write'],
            'riskTier' => RiskTier::Medium,
            'readOnly' => false,
            'destructive' => false,
            'idempotent' => false,
            'restMethod' => 'POST',
            'mcpToolName' => 'ability_content_create-page-draft',
        ]];
        yield 'content/delete-page' => [DeletePageAbility::class, [
            'name' => 'content/delete-page',
            'category' => 'content',
            'scopes' => ['pages:write'],
            'riskTier' => RiskTier::High,
            'readOnly' => false,
            'destructive' => true,
            'idempotent' => false,
            'restMethod' => 'DELETE',
            'mcpToolName' => 'ability_content_delete-page',
        ]];
        yield 'workspace/publish' => [PublishWorkspaceAbility::class, [
            'name' => 'workspace/publish',
            'category' => 'workspace',
            'scopes' => ['workspace:publish'],
            'riskTier' => RiskTier::High,
            'readOnly' => false,
            'destructive' => false,
            'idempotent' => true,
            'restMethod' => 'POST',
            'mcpToolName' => 'ability_workspace_publish',
        ]];
    }

    /**
     * @param class-string<AbilityInterface> $className
     * @param array<string, mixed> $expected
     */
    #[Test]
    #[DataProvider('definitions')]
    public function definitionCarriesGovernanceMetadata(string $className, array $expected): void
    {
        $definition = AbilityDefinition::fromClassName($className);

        self::assertSame($expected['name'], $definition->name);
        self::assertSame($expected['category'], $definition->category);
        self::assertSame($expected['scopes'], $definition->scopes);
        self::assertSame($expected['riskTier'], $definition->riskTier);
        self::assertSame($expected['readOnly'], $definition->isReadOnly());
        self::assertSame($expected['destructive'], $definition->destructive);
        self::assertSame($expected['idempotent'], $definition->idempotent);
        self::assertSame($expected['restMethod'], $definition->restMethod());
        self::assertSame($expected['mcpToolName'], $definition->mcpToolName());
        self::assertSame($expected['readOnly'], $definition->sideEffects === [], 'read-only iff no side effects');
        self::assertNotSame('', $definition->instructions, 'demo abilities carry agent instructions');
        self::assertSame(['mcp', 'cli', 'rest'], $definition->expose);
    }

    /**
     * @return iterable<string, array{AbilityInterface, array<string, mixed>, array<string, mixed>, array<string, mixed>}>
     */
    public static function contracts(): iterable
    {
        // SiteFinder is a readonly class and cannot be stubbed; the contract
        // assertions never reach it, so an uninitialised instance is enough.
        $search = new SearchContentAbility(
            self::createStub(ConnectionPool::class),
            (new \ReflectionClass(SiteFinder::class))->newInstanceWithoutConstructor(),
        );
        yield 'search: defaults and limits' => [
            $search,
            ['term' => 'typo3'],
            ['term' => 'typo3', 'limit' => 20, 'language' => null, 'tables' => ['pages', 'tt_content']],
            ['term' => 'x', 'limit' => 0, 'tables' => ['users']],
        ];

        yield 'create-page-draft: defaults and enum' => [
            new CreatePageDraftAbility(),
            ['parent' => 1, 'title' => 'News'],
            ['parent' => 1, 'title' => 'News', 'slug' => '', 'doktype' => 1],
            ['parent' => -1, 'title' => '', 'doktype' => 99],
        ];

        yield 'delete-page: recursive default' => [
            new DeletePageAbility(self::createStub(ConnectionPool::class)),
            ['uid' => 4],
            ['uid' => 4, 'recursive' => false],
            ['uid' => 0, 'recursive' => 'yes'],
        ];

        yield 'publish: dryRun default' => [
            new PublishWorkspaceAbility(self::createStub(ContainerInterface::class)),
            ['workspace' => 1],
            ['workspace' => 1, 'dryRun' => true],
            ['workspace' => 0, 'dryRun' => 'no'],
        ];
    }

    /**
     * @param array<string, mixed> $minimalInput
     * @param array<string, mixed> $expectedWithDefaults
     * @param array<string, mixed> $invalidInput
     */
    #[Test]
    #[DataProvider('contracts')]
    public function inputSchemaAppliesDefaultsAndRejectsInvalidInput(
        AbilityInterface $ability,
        array $minimalInput,
        array $expectedWithDefaults,
        array $invalidInput,
    ): void {
        $validator = new SchemaValidator();
        $schema = $ability->getInputSchema();

        $input = $validator->applyDefaults($minimalInput, $schema);
        self::assertSame($expectedWithDefaults, $input);
        self::assertSame([], $validator->validate($input, $schema, '$.input'));

        self::assertNotSame([], $validator->validate($invalidInput, $schema, '$.input'));
        self::assertNotSame([], $validator->validate(['unexpected' => 1, ...$minimalInput], $schema, '$.input'), 'additionalProperties is false');
        self::assertSame('object', $ability->getOutputSchema()['type']);
    }

    #[Test]
    public function writingAbilitiesDenyWithoutABackendUser(): void
    {
        unset($GLOBALS['BE_USER']);
        $context = ExecutionContext::cli();

        $draft = (new CreatePageDraftAbility())->checkPermission(['parent' => 1, 'title' => 'x'], $context);
        $delete = (new DeletePageAbility(self::createStub(ConnectionPool::class)))->checkPermission(['uid' => 1], $context);
        $publish = (new PublishWorkspaceAbility(self::createStub(ContainerInterface::class)))->checkPermission(['workspace' => 1], $context);

        foreach (['content/create-page-draft' => $draft, 'content/delete-page' => $delete, 'workspace/publish' => $publish] as $name => $denial) {
            self::assertIsString($denial, $name);
            self::assertStringContainsString('authenticated backend user', $denial);
            self::assertStringContainsString($name, $denial);
        }
    }
}
