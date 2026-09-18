<?php

declare(strict_types=1);

namespace Webconsulting\Abilities\Projection\Cli;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Webconsulting\Abilities\Catalog\CapabilityCatalog;
use Webconsulting\Abilities\Catalog\CapabilityEntry;
use Webconsulting\Abilities\Domain\AbilityDefinition;
use Webconsulting\Abilities\Domain\ExecutionContext;
use Webconsulting\Abilities\Registry\AbilitiesRegistry;

/**
 * CLI projection of the abilities registry: list every registered ability
 * with its governance metadata. With --source the same command lists one
 * source of the wider capability catalogue instead (see abilities:catalog).
 */
#[AsCommand(
    name: 'abilities:list',
    description: 'List all registered abilities with scopes, risk tier and side effects',
)]
final class ListAbilitiesCommand extends Command
{
    public function __construct(
        private readonly AbilitiesRegistry $registry,
        private readonly CapabilityCatalog $catalog,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('category', 'c', InputOption::VALUE_REQUIRED, 'Only list abilities of this category')
            ->addOption('source', 's', InputOption::VALUE_REQUIRED, 'List one catalogue source instead of the registry: abilities, mcp, skills, rest, cli')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output the full definitions as JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $source = $input->getOption('source');
        if (is_string($source) && $source !== '') {
            return $this->listSource($source, (bool)$input->getOption('json'), $output);
        }

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
            '%d abilities. Surfaces are projections of this registry: "mcp" appears as MCP tools (%s), "cli" runs via abilities:run. See abilities:catalog for everything else the installation can do.',
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

    private function listSource(string $source, bool $json, OutputInterface $output): int
    {
        if (!in_array($source, $this->catalog->sources(), true)) {
            $output->writeln(sprintf('<error>Unknown source "%s". Known: %s</error>', $source, implode(', ', $this->catalog->sources())));

            return Command::INVALID;
        }

        if ($json) {
            $output->writeln((string)json_encode(
                array_map(static fn(CapabilityEntry $entry): array => $entry->toArray(), $this->catalog->entries($source)),
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            ));

            return Command::SUCCESS;
        }

        CatalogCommand::renderTable($output, $this->catalog->entries($source));

        return Command::SUCCESS;
    }
}
