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

/**
 * CLI projection of the capability catalogue: everything the installation
 * can do, from every source, as a table or as JSON that can be handed to an
 * LLM as tool context.
 */
#[AsCommand(
    name: 'abilities:catalog',
    description: 'List every capability of this installation (abilities, MCP tools, skills, REST/webhook endpoints, console commands)',
)]
final class CatalogCommand extends Command
{
    public function __construct(
        private readonly CapabilityCatalog $catalog,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('source', 's', InputOption::VALUE_REQUIRED, 'Only this source: abilities, mcp, skills, rest, cli')
            ->addOption('surface', null, InputOption::VALUE_REQUIRED, 'Only entries invocable from this surface (mcp, cli, rest, webhook, scheduler, skills, php, frontend)')
            ->addOption('search', null, InputOption::VALUE_REQUIRED, 'Case-insensitive substring over id, title and description')
            ->addOption('format', 'f', InputOption::VALUE_REQUIRED, 'Output format: table or json', 'table');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $source = $input->getOption('source');
        $surface = $input->getOption('surface');
        $search = $input->getOption('search');
        $format = $input->getOption('format');
        if (!in_array($format, ['table', 'json'], true)) {
            $output->writeln('<error>--format must be "table" or "json".</error>');

            return Command::INVALID;
        }

        $source = is_string($source) && $source !== '' ? $source : null;
        $surface = is_string($surface) && $surface !== '' ? $surface : null;
        $search = is_string($search) ? $search : '';

        if ($format === 'json') {
            $output->writeln((string)json_encode(
                $this->catalog->toArray($source, $surface, $search),
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            ));

            return Command::SUCCESS;
        }

        $entries = $this->catalog->entries($source, $surface, $search);
        self::renderTable($output, $entries);
        $counts = $this->catalog->toArray($source, $surface, $search)['sources'];
        $output->writeln(sprintf(
            '%d capabilities (%s). Use --format=json for the machine-readable catalogue, abilities:describe <ability> for a full ability contract.',
            count($entries),
            implode(', ', array_map(static fn(string $slug, int $count): string => $slug . ': ' . $count, array_keys($counts), $counts)),
        ));

        return Command::SUCCESS;
    }

    /**
     * @param list<CapabilityEntry> $entries
     */
    public static function renderTable(OutputInterface $output, array $entries): void
    {
        if ($entries === []) {
            $output->writeln('No capabilities match.');

            return;
        }

        $table = new Table($output);
        $table->setHeaders(['ID', 'Title', 'Source', 'Surfaces', 'Annotations', 'Invocation']);
        foreach ($entries as $entry) {
            $flags = array_keys(array_filter($entry->annotations));
            $table->addRow([
                $entry->id,
                $entry->title,
                $entry->source,
                implode(', ', $entry->surfaces),
                $flags === [] ? '—' : implode(', ', $flags),
                implode("\n", $entry->invocations),
            ]);
        }
        $table->render();
    }
}
