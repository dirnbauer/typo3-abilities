<?php

declare(strict_types=1);

namespace Webconsulting\Abilities\Tests\Unit\Registry;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Webconsulting\Abilities\Registry\AbilitiesRegistry;
use Webconsulting\Abilities\Tests\Fixtures\CallbackAbility;
use Webconsulting\Abilities\Tests\Fixtures\EchoAbility;

final class AbilitiesRegistryTest extends TestCase
{
    #[Test]
    public function collectsAbilitiesSortedByName(): void
    {
        $registry = new AbilitiesRegistry([
            new EchoAbility(),
            new CallbackAbility(static fn(): mixed => null),
        ]);

        self::assertSame(['test/callback', 'test/echo'], $registry->getNames());
        self::assertTrue($registry->has('test/echo'));
        self::assertInstanceOf(EchoAbility::class, $registry->get('test/echo'));
        self::assertSame('test/echo', $registry->getDefinition('test/echo')->name);
    }

    #[Test]
    public function rejectsDuplicateAbilityNames(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionCode(7480291003);

        new AbilitiesRegistry([new EchoAbility(), new EchoAbility()]);
    }

    #[Test]
    public function filtersDefinitionsByCategory(): void
    {
        $registry = new AbilitiesRegistry([new EchoAbility()]);

        self::assertCount(1, $registry->getDefinitions('testing'));
        self::assertSame([], $registry->getDefinitions('content'));
    }

    #[Test]
    public function throwsOnUnknownAbility(): void
    {
        $registry = new AbilitiesRegistry([]);

        $this->expectException(\OutOfBoundsException::class);

        $registry->get('missing/ability');
    }
}
