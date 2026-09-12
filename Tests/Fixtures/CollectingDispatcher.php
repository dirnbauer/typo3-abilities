<?php

declare(strict_types=1);

namespace Webconsulting\Abilities\Tests\Fixtures;

use Psr\EventDispatcher\EventDispatcherInterface;

/** Records every dispatched event; optional listeners may mutate them. */
final class CollectingDispatcher implements EventDispatcherInterface
{
    /** @var list<object> */
    public array $events = [];

    /** @var list<\Closure(object): void> */
    private array $listeners = [];

    /**
     * @param \Closure(object): void $listener
     */
    public function listen(\Closure $listener): void
    {
        $this->listeners[] = $listener;
    }

    public function dispatch(object $event): object
    {
        $this->events[] = $event;
        foreach ($this->listeners as $listener) {
            $listener($event);
        }

        return $event;
    }

    /**
     * @template T of object
     * @param class-string<T> $class
     * @return list<T>
     */
    public function of(string $class): array
    {
        return array_values(array_filter($this->events, static fn(object $event): bool => $event instanceof $class));
    }
}
