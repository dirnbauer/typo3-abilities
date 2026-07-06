<?php

declare(strict_types=1);

namespace Webconsulting\Abilities\Tests\Unit\Domain;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Webconsulting\Abilities\Domain\AbilityDefinition;
use Webconsulting\Abilities\Domain\ExecutionContext;
use Webconsulting\Abilities\Domain\RiskTier;
use Webconsulting\Abilities\Registry\AbstractAbility;
use Webconsulting\Abilities\Tests\Fixtures\CallbackAbility;
use Webconsulting\Abilities\Tests\Fixtures\EchoAbility;

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
}
