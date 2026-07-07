<?php

declare(strict_types=1);

namespace Webconsulting\Abilities\Projection\Cli;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Core\Bootstrap;
use Webconsulting\Abilities\Domain\ExecutionContext;
use Webconsulting\Abilities\Execution\AbilityExecutor;
use Webconsulting\Abilities\Registry\AbilitiesRegistry;

/**
 * CLI projection: execute one ability through the full pipeline (policy →
 * input validation → scopes → permission → execute → output validation).
 * The CLI is a trusted surface; the abilities policy still applies, and
 * review-gated abilities need an explicit --approve-review.
 */
#[AsCommand(
    name: 'abilities:run',
    description: 'Execute an ability through the governed pipeline and print its result as JSON',
)]
final class RunAbilityCommand extends Command
{
    public function __construct(
        private readonly AbilitiesRegistry $registry,
        private readonly AbilityExecutor $executor,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('name', InputArgument::REQUIRED, 'Ability name, e.g. "system/site-info"')
            ->addOption('input', 'i', InputOption::VALUE_REQUIRED, 'Ability input as a JSON object', '{}')
            ->addOption(
                'approve-review',
                null,
                InputOption::VALUE_NONE,
                'Mark this execution as human-approved for review_required policy rules',
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // Abilities check real backend permissions and may run DataHandler:
        // boot the _cli_ backend user like every writing TYPO3 command does.
        // TYPO3's CLI pre-sets an UNAUTHENTICATED BE_USER shell, so the gate
        // is "no logged-in user yet", not "no global". Plain unit runs
        // (no TYPO3 constant) skip the boot; abilities needing a backend
        // user then deny via checkPermission().
        $backendUser = $GLOBALS['BE_USER'] ?? null;
        $userId = $backendUser instanceof BackendUserAuthentication ? ($backendUser->user['uid'] ?? null) : null;
        $hasAuthenticatedUser = is_numeric($userId) && (int)$userId > 0;
        if (defined('TYPO3') && !$hasAuthenticatedUser) {
            Bootstrap::initializeBackendAuthentication();
        }

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

        $definition = $this->registry->getDefinition($name);
        if (!$definition->isExposedTo(ExecutionContext::SURFACE_CLI)) {
            $output->writeln(sprintf('<error>Ability "%s" is not exposed to the CLI surface.</error>', $name));

            return Command::FAILURE;
        }

        $rawInput = $input->getOption('input');
        try {
            $abilityInput = json_decode(is_string($rawInput) ? $rawInput : '{}', true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            $output->writeln(sprintf('<error>--input is not valid JSON: %s</error>', $exception->getMessage()));

            return Command::INVALID;
        }
        if (!is_array($abilityInput)) {
            $output->writeln('<error>--input must be a JSON object.</error>');

            return Command::INVALID;
        }

        $objectInput = [];
        foreach ($abilityInput as $key => $value) {
            $objectInput[(string)$key] = $value;
        }

        $result = $this->executor->execute(
            $this->registry->get($name),
            $objectInput,
            ExecutionContext::cli(reviewApproved: (bool)$input->getOption('approve-review')),
        );

        $output->writeln((string)json_encode(
            $result->toArray(),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ));

        return $result->ok ? Command::SUCCESS : Command::FAILURE;
    }
}
