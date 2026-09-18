<?php

declare(strict_types=1);

namespace Webconsulting\Abilities\Registry;

use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Webconsulting\Abilities\Category\CategoryRegistry;
use Webconsulting\Abilities\Domain\AbilityDefinition;
use Webconsulting\Abilities\Event\ModifyAbilityDefinitionEvent;

/**
 * The abilities registry: one typed, permissioned registry of what this
 * installation can do. All services tagged "abilities.ability" (i.e. every
 * AbilityInterface implementation known to the DI container) are collected
 * here; MCP tools, CLI commands and REST routes are projections of this
 * registry — never hand-rolled endpoints.
 *
 * Every definition passes through ModifyAbilityDefinitionEvent so an
 * installation can override exposure, risk tier or read-only status.
 * Unknown categories are logged, not fatal.
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
        private readonly ?CategoryRegistry $categoryRegistry = null,
        private readonly ?EventDispatcherInterface $eventDispatcher = null,
        private readonly ?LoggerInterface $logger = null,
    ) {
        foreach ($abilities as $ability) {
            $definition = $this->buildDefinition($ability);
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
    public function getDefinitions(?string $category = null, ?string $surface = null): array
    {
        return array_filter(
            $this->definitions,
            static fn(AbilityDefinition $definition): bool
                => ($category === null || $definition->category === $category)
                && ($surface === null || $definition->isExposedTo($surface)),
        );
    }

    /**
     * The full contract of one ability — the registry entry plus both JSON
     * Schemas — as every surface publishes it (abilities:describe, REST
     * describe, abilities/describe, backend module). Empty schemas are
     * emitted as {} so JSON consumers always see an object.
     *
     * @return array<string, mixed>
     */
    public function describe(string $name): array
    {
        $ability = $this->get($name);

        return [
            ...$this->getDefinition($name)->toArray(),
            'inputSchema' => $ability->getInputSchema() ?: new \stdClass(),
            'outputSchema' => $ability->getOutputSchema() ?: new \stdClass(),
        ];
    }

    /**
     * @return list<string>
     */
    public function getNames(): array
    {
        return array_keys($this->definitions);
    }

    /**
     * Every scope declared by at least one registered ability, sorted.
     *
     * @return list<string>
     */
    public function getDeclaredScopes(): array
    {
        $scopes = [];
        foreach ($this->definitions as $definition) {
            foreach ($definition->scopes as $scope) {
                $scopes[$scope] = true;
            }
        }
        ksort($scopes);

        return array_keys($scopes);
    }

    /**
     * Category slugs referenced by at least one registered ability, sorted.
     *
     * @return list<string>
     */
    public function getCategoriesInUse(): array
    {
        $categories = [];
        foreach ($this->definitions as $definition) {
            $categories[$definition->category] = true;
        }
        ksort($categories);

        return array_keys($categories);
    }

    private function buildDefinition(AbilityInterface $ability): AbilityDefinition
    {
        $definition = AbilityDefinition::fromInstance($ability);

        if ($this->eventDispatcher !== null) {
            $event = new ModifyAbilityDefinitionEvent($definition);
            $this->eventDispatcher->dispatch($event);
            $definition = $event->getDefinition();
        }

        if ($this->categoryRegistry !== null && !$this->categoryRegistry->has($definition->category)) {
            $this->logger?->warning(
                'Ability "{ability}" references unknown category "{category}"; register it via #[AsAbilityCategory] or an AbilityCategoryProviderInterface service.',
                ['ability' => $definition->name, 'category' => $definition->category],
            );
        }

        return $definition;
    }
}
