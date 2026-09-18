<?php

declare(strict_types=1);

namespace Webconsulting\Abilities\Projection\Cli;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Webconsulting\Abilities\Security\TokenService;

#[AsCommand(
    name: 'abilities:token:revoke',
    description: 'Revoke a REST bearer token by uid',
)]
final class TokenRevokeCommand extends Command
{
    public function __construct(
        private readonly TokenService $tokenService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('uid', InputArgument::REQUIRED, 'Token uid (see abilities:token:list)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $uid = $input->getArgument('uid');
        if (!is_numeric($uid) || (int)$uid <= 0) {
            $output->writeln('<error>uid must be a positive integer.</error>');

            return Command::INVALID;
        }

        if (!$this->tokenService->revoke((int)$uid)) {
            $output->writeln(sprintf('<error>No active token with uid %d.</error>', (int)$uid));

            return Command::FAILURE;
        }

        $output->writeln(sprintf('Token #%d revoked.', (int)$uid));

        return Command::SUCCESS;
    }
}
