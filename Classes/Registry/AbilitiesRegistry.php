<?php

declare(strict_types=1);

namespace Webconsulting\Abilities\Registry;

use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Webconsulting\Abilities\Domain\AbilityDefinition;

/**
 * The abilities registry: one typed, permissioned registry of what this
 * installation can do. All services tagged "abilities.ability" (i.e. every
 * AbilityInterface implementation known to the DI container) are collected
 * here; MCP tools, CLI commands and REST routes are projections of this
 * registry — never hand-rolled endpoints.
 */
final class AbilitiesRegistry
{
    /** @var array<string, AbilityInterface> */
    private array $abilities = [];

    /** @var array<string, AbilityDefinition> */
    private array $definitions = [];

    /**
     * @param iterable<AbilityInterface> $abilities
     */
    public function __construct(
        #[AutowireIterator('abilities.ability')]
        iterable $abilities,
    ) {
        foreach ($abilities as $ability) {
            $definition = AbilityDefinition::fromInstance($ability);
            if (isset($this->definitions[$definition->name])) {
                throw new \LogicException(
                    sprintf(
                        'Duplicate ability name "%s": declared by both %s and %s.',
                        $definition->name,
                        $this->definitions[$definition->name]->className,
                        $definition->className,
                    ),
                    7480291003,
                );
            }
            $this->abilities[$definition->name] = $ability;
            $this->definitions[$definition->name] = $definition;
        }

        ksort($this->abilities);
        ksort($this->definitions);
    }

    public function has(string $name): bool
    {
        return isset($this->abilities[$name]);
    }

    public function get(string $name): AbilityInterface
    {
        return $this->abilities[$name]
            ?? throw new \OutOfBoundsException(sprintf('Unknown ability "%s".', $name), 7480291004);
    }

    public function getDefinition(string $name): AbilityDefinition
    {
        return $this->definitions[$name]
            ?? throw new \OutOfBoundsException(sprintf('Unknown ability "%s".', $name), 7480291005);
    }

    /**
     * @return array<string, AbilityDefinition> keyed and sorted by ability name
     */
    public function getDefinitions(?string $category = null): array
    {
        if ($category === null) {
            return $this->definitions;
        }

        return array_filter(
            $this->definitions,
            fn(AbilityDefinition $definition): bool => $definition->category === $category,
        );
    }

    /**
     * @return list<string>
     */
    public function getNames(): array
    {
        return array_keys($this->definitions);
    }
}
