<?php

declare(strict_types=1);

namespace Webconsulting\Abilities\Projection\Cli;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Webconsulting\Abilities\Registry\AbilitiesRegistry;

/**
 * CLI projection: show one ability's full contract — metadata plus input
 * and output JSON Schemas — as JSON. This is the machine-readable form of
 * a registry entry.
 */
#[AsCommand(
    name: 'abilities:describe',
    description: 'Show one ability\'s full definition including input/output schemas as JSON',
)]
final class DescribeAbilityCommand extends Command
{
    public function __construct(
        private readonly AbilitiesRegistry $registry,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('name', InputArgument::REQUIRED, 'Ability name, e.g. "system/site-info"');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $name = $input->getArgument('name');
        $name = is_string($name) ? $name : '';
        if (!$this->registry->has($name)) {
            $output->writeln(sprintf(
                '<error>Unknown ability "%s". Known: %s</error>',
                $name,
                implode(', ', $this->registry->getNames()) ?: '(none)',
            ));

            return Command::FAILURE;
        }

        $ability = $this->registry->get($name);
        $definition = $this->registry->getDefinition($name);

        $output->writeln((string)json_encode(
            [
                ...$definition->toArray(),
                'inputSchema' => $ability->getInputSchema() ?: new \stdClass(),
                'outputSchema' => $ability->getOutputSchema() ?: new \stdClass(),
            ],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ));

        return Command::SUCCESS;
    }
}
