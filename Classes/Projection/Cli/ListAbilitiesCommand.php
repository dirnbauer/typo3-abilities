<?php

declare(strict_types=1);

namespace Webconsulting\Abilities\Projection\Cli;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Webconsulting\Abilities\Domain\AbilityDefinition;
use Webconsulting\Abilities\Domain\ExecutionContext;
use Webconsulting\Abilities\Registry\AbilitiesRegistry;

/**
 * CLI projection of the abilities registry: list every registered ability
 * with its governance metadata.
 */
#[AsCommand(
    name: 'abilities:list',
    description: 'List all registered abilities with scopes, risk tier and side effects',
)]
final class ListAbilitiesCommand extends Command
{
    public function __construct(
        private readonly AbilitiesRegistry $registry,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('category', 'c', InputOption::VALUE_REQUIRED, 'Only list abilities of this category')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output the full definitions as JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $category = $input->getOption('category');
        $definitions = $this->registry->getDefinitions(is_string($category) ? $category : null);

        if ($input->getOption('json')) {
            $output->writeln((string)json_encode(
                array_values(array_map(
                    static fn(AbilityDefinition $definition): array => $definition->toArray(),
                    $definitions,
                )),
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            ));

            return Command::SUCCESS;
        }

        if ($definitions === []) {
            $output->writeln('No abilities registered' . (is_string($category) ? sprintf(' in category "%s"', $category) : '') . '.');

            return Command::SUCCESS;
        }

        $table = new Table($output);
        $table->setHeaders(['Ability', 'Title', 'Category', 'Risk', 'Scopes', 'Side effects', 'Surfaces']);
        foreach ($definitions as $definition) {
            $table->addRow([
                $definition->name,
                $definition->title,
                $definition->category,
                $definition->riskTier->value,
                implode("\n", $definition->scopes) ?: '—',
                implode("\n", $definition->sideEffects) ?: 'read-only',
                implode(', ', $definition->expose),
            ]);
        }
        $table->render();

        $output->writeln(sprintf(
            '%d abilities. Surfaces are projections of this registry: "mcp" appears as MCP tools (%s), "cli" runs via abilities:run.',
            count($definitions),
            implode(', ', array_map(
                static fn(AbilityDefinition $definition): string => $definition->mcpToolName(),
                array_values(array_filter(
                    $definitions,
                    static fn(AbilityDefinition $definition): bool => $definition->isExposedTo(ExecutionContext::SURFACE_MCP),
                )),
            )) ?: 'none exposed',
        ));

        return Command::SUCCESS;
    }
}
