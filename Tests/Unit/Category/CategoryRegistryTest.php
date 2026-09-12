<?php

declare(strict_types=1);

namespace Webconsulting\Abilities\Tests\Unit\Category;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Webconsulting\Abilities\Category\AbilityCategoryProviderInterface;
use Webconsulting\Abilities\Category\AsAbilityCategory;
use Webconsulting\Abilities\Category\CategoryRegistry;
use Webconsulting\Abilities\Domain\AbilityCategory;

#[AsAbilityCategory(slug: 'news', label: 'News', description: 'Editorial news.')]
#[AsAbilityCategory(slug: 'events', label: 'Events')]
final class AttributedCategories
{
}

final class ProvidedCategories implements AbilityCategoryProviderInterface
{
    public function getAbilityCategories(): iterable
    {
        yield new AbilityCategory('shop', 'Shop', 'Commerce.');
        yield new AbilityCategory('system', 'System (overridden)', 'Replaced built-in.');
    }
}

final class CategoryRegistryTest extends TestCase
{
    #[Test]
    public function shipsTheBuiltInVocabulary(): void
    {
        $registry = new CategoryRegistry();

        foreach (['system', 'content', 'site', 'search', 'workspace', 'forms', 'registry', 'demo', 'general'] as $slug) {
            self::assertTrue($registry->has($slug), $slug);
        }
        self::assertSame('Registry', $registry->get('registry')->label);
        self::assertSame($registry->slugs(), array_keys($registry->all()));
        self::assertSame(array_keys($registry->all()), (static function (array $slugs): array {
            sort($slugs);

            return $slugs;
        })(array_keys($registry->all())), 'sorted by slug');
    }

    #[Test]
    public function collectsAttributedAndProvidedCategories(): void
    {
        $registry = new CategoryRegistry([new AttributedCategories(), new ProvidedCategories()]);

        self::assertSame('Editorial news.', $registry->get('news')->description);
        self::assertSame('Events', $registry->get('events')->label);
        self::assertSame('Shop', $registry->get('shop')->label);
        self::assertSame('System (overridden)', $registry->get('system')->label, 'providers may override built-ins');
    }

    #[Test]
    public function registerAddsAtRuntimeAndUnknownSlugsThrow(): void
    {
        $registry = new CategoryRegistry();
        $registry->register(new AbilityCategory('custom', 'Custom'));

        self::assertTrue($registry->has('custom'));
        self::assertSame(['slug' => 'custom', 'label' => 'Custom', 'description' => ''], $registry->get('custom')->toArray());

        $this->expectException(\OutOfBoundsException::class);
        $this->expectExceptionCode(7480291011);
        $registry->get('nope');
    }

    #[Test]
    public function rejectsInvalidSlugs(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionCode(7480291010);

        new AbilityCategory('Not A Slug', 'X');
    }
}
