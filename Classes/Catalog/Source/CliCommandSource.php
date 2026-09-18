<?php

declare(strict_types=1);

namespace Webconsulting\Abilities\Catalog\Source;

use Symfony\Component\Console\Input\InputDefinition;
use TYPO3\CMS\Core\Console\CommandRegistry;
use Webconsulting\Abilities\Catalog\CapabilityEntry;
use Webconsulting\Abilities\Catalog\CapabilitySourceInterface;

/**
 * Every non-hidden console command of the installation, with a JSON Schema
 * derived from its InputDefinition (arguments and options) and, for
 * schedulable commands, the scheduler surface.
 */
final class CliCommandSource implements CapabilitySourceInterface
{
    public const SURFACE_SCHEDULER = 'scheduler';

    public function __construct(
        private readonly CommandRegistry $commandRegistry,
    ) {}

    public function getSource(): string
    {
        return CapabilityEntry::SOURCE_CLI;
    }

    public function getCapabilities(): iterable
    {
        foreach ($this->commandRegistry->filter() as $name => $configuration) {
            $name = (string)$name;
            try {
                $definition = $this->commandRegistry->get($name)->getDefinition();
            } catch (\Throwable) {
                // A command whose service cannot be built is still listed — without a schema.
                $definition = null;
            }

            yield self::entry(
                $name,
                is_string($configuration['description'] ?? null) ? $configuration['description'] : '',
                (bool)($configuration['schedulable'] ?? false),
                $definition,
            );
        }
    }

    public static function entry(string $name, string $description, bool $schedulable, ?InputDefinition $definition): CapabilityEntry
    {
        $invocations = [CapabilityEntry::SOURCE_CLI => 'vendor/bin/typo3 ' . $name];
        if ($schedulable) {
            $invocations[self::SURFACE_SCHEDULER] = sprintf('Scheduler task "Execute console command" → %s', $name);
        }

        return new CapabilityEntry(
            id: 'cli/' . $name,
            title: $name,
            description: $description,
            source: CapabilityEntry::SOURCE_CLI,
            surfaces: array_keys($invocations),
            inputSchema: $definition === null ? [] : self::schema($definition),
            annotations: CapabilityEntry::annotations(),
            invocations: $invocations,
            meta: ['schedulable' => $schedulable],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private static function schema(InputDefinition $definition): array
    {
        $meaningful = static fn(mixed $value): bool => $value !== null && $value !== '' && $value !== false && $value !== [];
        $properties = [];
        $required = [];
        foreach ($definition->getArguments() as $argument) {
            $properties[$argument->getName()] = array_filter([
                'type' => $argument->isArray() ? 'array' : 'string',
                'description' => $argument->getDescription(),
                'default' => $argument->getDefault(),
            ], $meaningful);
            if ($argument->isRequired()) {
                $required[] = $argument->getName();
            }
        }
        foreach ($definition->getOptions() as $option) {
            $properties['--' . $option->getName()] = array_filter([
                'type' => $option->isArray() ? 'array' : ($option->acceptValue() ? 'string' : 'boolean'),
                'description' => $option->getDescription(),
                'default' => $option->getDefault(),
            ], $meaningful);
        }

        $schema = ['type' => 'object', 'properties' => $properties];
        if ($required !== []) {
            $schema['required'] = $required;
        }

        return $schema;
    }
}
