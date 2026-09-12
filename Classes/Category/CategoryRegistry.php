<?php

declare(strict_types=1);

namespace Webconsulting\Abilities\Category;

use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Webconsulting\Abilities\Domain\AbilityCategory;

/**
 * Registry of ability categories: the built-in vocabulary plus everything
 * DI-tagged providers contribute (AbilityCategoryProviderInterface
 * implementations and #[AsAbilityCategory]-attributed classes).
 *
 * An ability referencing an unknown category is not fatal — the
 * AbilitiesRegistry logs a warning and lists it anyway; discovery surfaces
 * simply have no label to show for that slug.
 */
final class CategoryRegistry
{
    /**
     * @var array<string, array{string, string}> slug => [label, description]
     */
    public const BUILT_IN = [
        'system' => ['System', 'Installation, environment and runtime information.'],
        'content' => ['Content', 'Pages, content elements and records: create, update, move, delete.'],
        'site' => ['Site', 'Site configuration, languages, routing and domains.'],
        'search' => ['Search', 'Full-text and structured search over the installation.'],
        'workspace' => ['Workspace', 'Draft workspaces: stage, review, publish, discard.'],
        'forms' => ['Forms', 'Form definitions and submissions.'],
        'registry' => ['Registry', 'Introspection of the abilities registry itself.'],
        'demo' => ['Demo', 'Examples and reference abilities.'],
        'general' => ['General', 'Abilities without a more specific category.'],
    ];

    /** @var array<string, AbilityCategory> */
    private array $categories = [];

    /**
     * @param iterable<object> $providers
     */
    public function __construct(
        #[AutowireIterator('abilities.category_provider')]
        iterable $providers = [],
    ) {
        foreach (self::BUILT_IN as $slug => [$label, $description]) {
            $this->categories[$slug] = new AbilityCategory($slug, $label, $description);
        }

        foreach ($providers as $provider) {
            foreach ($this->categoriesOf($provider) as $category) {
                $this->register($category);
            }
        }
    }

    /**
     * Register (or override) a category at runtime.
     */
    public function register(AbilityCategory $category): void
    {
        $this->categories[$category->slug] = $category;
        ksort($this->categories);
    }

    public function has(string $slug): bool
    {
        return isset($this->categories[$slug]);
    }

    public function get(string $slug): AbilityCategory
    {
        return $this->categories[$slug]
            ?? throw new \OutOfBoundsException(sprintf('Unknown ability category "%s".', $slug), 7480291011);
    }

    /**
     * @return array<string, AbilityCategory> keyed and sorted by slug
     */
    public function all(): array
    {
        return $this->categories;
    }

    /**
     * @return list<string>
     */
    public function slugs(): array
    {
        return array_keys($this->categories);
    }

    /**
     * @return iterable<AbilityCategory>
     */
    private function categoriesOf(object $provider): iterable
    {
        if ($provider instanceof AbilityCategoryProviderInterface) {
            yield from $provider->getAbilityCategories();
        }

        foreach ((new \ReflectionClass($provider))->getAttributes(AsAbilityCategory::class) as $attribute) {
            $declared = $attribute->newInstance();
            yield new AbilityCategory($declared->slug, $declared->label, $declared->description);
        }
    }
}
