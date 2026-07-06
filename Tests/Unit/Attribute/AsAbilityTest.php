<?php

declare(strict_types=1);

namespace Webconsulting\Abilities\Tests\Unit\Attribute;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Webconsulting\Abilities\Attribute\AsAbility;
use Webconsulting\Abilities\Domain\RiskTier;

final class AsAbilityTest extends TestCase
{
    #[Test]
    public function acceptsValidNameAndAppliesDefaults(): void
    {
        $attribute = new AsAbility(name: 'news/create-article', title: 'Create', description: 'Creates.');

        self::assertSame('news/create-article', $attribute->name);
        self::assertSame('general', $attribute->category);
        self::assertSame([], $attribute->scopes);
        self::assertSame(RiskTier::Low, $attribute->riskTier);
        self::assertSame([], $attribute->sideEffects);
        self::assertFalse($attribute->idempotent);
        self::assertFalse($attribute->destructive);
        self::assertSame(['mcp', 'cli', 'rest'], $attribute->expose);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidNames(): iterable
    {
        yield 'missing namespace' => ['create-article'];
        yield 'uppercase' => ['News/Create'];
        yield 'underscore' => ['news/create_article'];
        yield 'two slashes' => ['news/create/article'];
        yield 'leading dash' => ['news/-create'];
        yield 'empty' => [''];
        yield 'trailing slash' => ['news/'];
    }

    #[Test]
    #[DataProvider('invalidNames')]
    public function rejectsInvalidNames(string $name): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionCode(7480291001);

        new AsAbility(name: $name, title: 'X', description: 'X');
    }
}
