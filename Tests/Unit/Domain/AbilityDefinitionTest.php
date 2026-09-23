<?php

declare(strict_types=1);

namespace Webconsulting\Abilities\Tests\Unit\Domain;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Webconsulting\Abilities\Domain\AbilityDefinition;
use Webconsulting\Abilities\Domain\ExecutionContext;
use Webconsulting\Abilities\Domain\RiskTier;
use Webconsulting\Abilities\Registry\AbilityInterface;
use Webconsulting\Abilities\Registry\AbstractAbility;
use Webconsulting\Abilities\Tests\Fixtures\CallbackAbility;
use Webconsulting\Abilities\Tests\Fixtures\EchoAbility;
use Webconsulting\Abilities\Tests\Fixtures\WriteAbility;

final class AbilityDefinitionTest extends TestCase
{
    #[Test]
    public function buildsDefinitionFromAttributedInstance(): void
    {
        $definition = AbilityDefinition::fromInstance(new EchoAbility());

        self::assertSame('test/echo', $definition->name);
        self::assertSame('Echo', $definition->title);
        self::assertSame('testing', $definition->category);
        self::assertSame(['testing:read'], $definition->scopes);
        self::assertSame(RiskTier::Low, $definition->riskTier);
        self::assertTrue($definition->isReadOnly());
        self::assertTrue($definition->idempotent);
        self::assertSame(EchoAbility::class, $definition->className);
    }

    #[Test]
    public function throwsForClassWithoutAttribute(): void
    {
        $ability = new class extends AbstractAbility {
            public function execute(array $input, ExecutionContext $context): mixed
            {
                return null;
            }
        };

        $this->expectException(\LogicException::class);
        $this->expectExceptionCode(7480291002);

        AbilityDefinition::fromInstance($ability);
    }

    #[Test]
    public function mapsAbilityNameToMcpToolName(): void
    {
        $definition = AbilityDefinition::fromInstance(new EchoAbility());

        self::assertSame('ability_test_echo', $definition->mcpToolName());
    }

    #[Test]
    public function sideEffectsMakeDefinitionNonReadOnly(): void
    {
        $definition = AbilityDefinition::fromInstance(new CallbackAbility(static fn(): mixed => null));

        self::assertFalse($definition->isReadOnly());
        self::assertTrue($definition->destructive);
        self::assertSame(['database:write'], $definition->sideEffects);
    }

    #[Test]
    public function exposureDefaultsToAllSurfaces(): void
    {
        $definition = AbilityDefinition::fromInstance(new EchoAbility());

        self::assertTrue($definition->isExposedTo(ExecutionContext::SURFACE_MCP));
        self::assertTrue($definition->isExposedTo(ExecutionContext::SURFACE_CLI));
        self::assertTrue($definition->isExposedTo(ExecutionContext::SURFACE_REST));
        self::assertFalse($definition->isExposedTo('smoke-signals'));
    }

    #[Test]
    public function toArrayContainsTheFullRegistrySchema(): void
    {
        $array = AbilityDefinition::fromInstance(new EchoAbility())->toArray();

        self::assertSame('test/echo', $array['name']);
        self::assertSame('low', $array['riskTier']);
        self::assertTrue($array['readOnly']);
        self::assertSame('ability_test_echo', $array['mcpToolName']);
        self::assertArrayHasKey('scopes', $array);
        self::assertArrayHasKey('sideEffects', $array);
        self::assertArrayHasKey('expose', $array);
    }

    #[Test]
    public function annotationsFollowTheWordPressShape(): void
    {
        $definition = AbilityDefinition::fromClassName(WriteAbility::class);

        self::assertSame(
            ['readonly' => false, 'destructive' => false, 'idempotent' => false, 'instructions' => 'Send the value to store.'],
            $definition->annotations(),
        );
        self::assertSame('Send the value to store.', $definition->instructions);
        self::assertSame('testing', $definition->category);
    }

    /**
     * @return iterable<string, array{class-string<AbilityInterface>, string}>
     */
    public static function restMethods(): iterable
    {
        yield 'read-only → GET' => [EchoAbility::class, 'GET'];
        yield 'write → POST' => [WriteAbility::class, 'POST'];
        yield 'destructive → DELETE' => [CallbackAbility::class, 'DELETE'];
    }

    /**
     * @param class-string<AbilityInterface> $className
     */
    #[Test]
    #[DataProvider('restMethods')]
    public function restMethodIsDerivedFromAnnotations(string $className, string $method): void
    {
        self::assertSame($method, AbilityDefinition::fromClassName($className)->restMethod());
    }

    #[Test]
    public function explicitReadOnlyWinsOverDestructiveForTheRestMethod(): void
    {
        $definition = AbilityDefinition::fromClassName(CallbackAbility::class)->with(readOnly: true);

        self::assertTrue($definition->isReadOnly());
        self::assertSame('GET', $definition->restMethod());
    }

    #[Test]
    public function withProducesAnOverriddenCopy(): void
    {
        $original = AbilityDefinition::fromInstance(new EchoAbility());
        $modified = $original->with(expose: ['cli'], riskTier: RiskTier::High, readOnly: false);

        self::assertSame(['mcp', 'cli', 'rest'], $original->expose);
        self::assertSame(['cli'], $modified->expose);
        self::assertSame(RiskTier::High, $modified->riskTier);
        self::assertFalse($modified->isReadOnly());
        self::assertSame($original->name, $modified->name);
        self::assertSame($original->className, $modified->className);
    }

    #[Test]
    public function toArrayCarriesAnnotationsInstructionsAndRestMethod(): void
    {
        $array = AbilityDefinition::fromClassName(WriteAbility::class)->toArray();

        self::assertSame('POST', $array['restMethod']);
        self::assertSame('Send the value to store.', $array['instructions']);
        self::assertSame(['readonly' => false, 'destructive' => false, 'idempotent' => false, 'instructions' => 'Send the value to store.'], $array['annotations']);
    }
}
