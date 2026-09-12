<?php

declare(strict_types=1);

namespace Webconsulting\Abilities\Tests\Unit\Registry;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Webconsulting\Abilities\Category\CategoryRegistry;
use Webconsulting\Abilities\Domain\ExecutionContext;
use Webconsulting\Abilities\Domain\RiskTier;
use Webconsulting\Abilities\Event\ModifyAbilityDefinitionEvent;
use Webconsulting\Abilities\Registry\AbilitiesRegistry;
use Webconsulting\Abilities\Tests\Fixtures\CollectingDispatcher;
use Webconsulting\Abilities\Tests\Fixtures\HiddenAbility;
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

    #[Test]
    public function modifyEventCanOverrideGovernanceFacts(): void
    {
        $dispatcher = new CollectingDispatcher();
        $dispatcher->listen(static function (object $event): void {
            if ($event instanceof ModifyAbilityDefinitionEvent && $event->getDefinition()->name === 'test/echo') {
                $event->setExpose(['cli']);
                $event->setRiskTier(RiskTier::Critical);
                $event->setReadOnly(false);
            }
        });

        $registry = new AbilitiesRegistry([new EchoAbility(), new CallbackAbility(static fn(): mixed => null)], null, $dispatcher);

        $echo = $registry->getDefinition('test/echo');
        self::assertSame(['cli'], $echo->expose);
        self::assertSame(RiskTier::Critical, $echo->riskTier);
        self::assertFalse($echo->isReadOnly());
        self::assertCount(2, $dispatcher->of(ModifyAbilityDefinitionEvent::class));
        self::assertSame(['mcp', 'cli', 'rest'], $registry->getDefinition('test/callback')->expose, 'other abilities untouched');
    }

    #[Test]
    public function modifyEventRefusesToSwapTheAbility(): void
    {
        $event = new ModifyAbilityDefinitionEvent(\Webconsulting\Abilities\Domain\AbilityDefinition::fromClassName(EchoAbility::class));

        $this->expectException(\LogicException::class);
        $this->expectExceptionCode(7480291012);
        $event->setDefinition(\Webconsulting\Abilities\Domain\AbilityDefinition::fromClassName(HiddenAbility::class));
    }

    #[Test]
    public function unknownCategoryIsLoggedNotFatal(): void
    {
        $logger = new class () extends AbstractLogger {
            /** @var list<string> */
            public array $warnings = [];

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                $this->warnings[] = $level . ': ' . strtr((string)$message, ['{ability}' => (string)($context['ability'] ?? ''), '{category}' => (string)($context['category'] ?? '')]);
            }
        };

        $registry = new AbilitiesRegistry([new EchoAbility()], new CategoryRegistry(), null, $logger);

        self::assertTrue($registry->has('test/echo'));
        self::assertCount(1, $logger->warnings);
        self::assertStringContainsString('warning: Ability "test/echo" references unknown category "testing"', $logger->warnings[0]);
    }

    #[Test]
    public function knownCategoryDoesNotWarn(): void
    {
        $categories = new CategoryRegistry();
        $categories->register(new \Webconsulting\Abilities\Domain\AbilityCategory('testing', 'Testing'));
        $logger = new class () extends AbstractLogger {
            public int $calls = 0;

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                $this->calls++;
            }
        };

        new AbilitiesRegistry([new EchoAbility()], $categories, null, $logger);

        self::assertSame(0, $logger->calls);
    }

    #[Test]
    public function filtersBySurfaceAndListsDeclaredScopesAndCategories(): void
    {
        $registry = new AbilitiesRegistry([new EchoAbility(), new HiddenAbility(), new CallbackAbility(static fn(): mixed => null)]);

        self::assertSame(['test/callback', 'test/echo'], array_keys($registry->getDefinitions(null, ExecutionContext::SURFACE_MCP)));
        self::assertSame(['test/callback', 'test/echo', 'test/hidden'], array_keys($registry->getDefinitions('testing', ExecutionContext::SURFACE_CLI)));
        self::assertSame(['testing:read', 'testing:write'], $registry->getDeclaredScopes());
        self::assertSame(['testing'], $registry->getCategoriesInUse());
    }
}
