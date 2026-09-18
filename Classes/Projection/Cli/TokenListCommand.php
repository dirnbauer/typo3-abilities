<?php

declare(strict_types=1);

namespace Webconsulting\Abilities\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Webconsulting\Abilities\Security\Token;
use Webconsulting\Abilities\Security\TokenService;

#[AsCommand(
    name: 'abilities:token:list',
    description: 'List the active REST bearer tokens (hashes are never shown)',
)]
final class TokenListCommand extends Command
{
    public function __construct(
        private readonly TokenService $tokenService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('json', null, InputOption::VALUE_NONE, 'Print the list as JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $tokens = $this->tokenService->list();

        if ($input->getOption('json')) {
            $output->writeln((string)json_encode(
                array_map(static fn(Token $token): array => $token->toArray(), $tokens),
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
            ));

            return Command::SUCCESS;
        }

        if ($tokens === []) {
            $output->writeln('No active tokens. Create one with abilities:token:create.');

            return Command::SUCCESS;
        }

        $table = new Table($output);
        $table->setHeaders(['UID', 'Name', 'BE user', 'Scopes', 'Expires', 'Last used', 'Created']);
        $now = time();
        foreach ($tokens as $token) {
            $table->addRow([
                $token->uid,
                $token->name,
                $token->backendUserUid,
                implode("\n", $token->scopes) ?: '—',
                $token->expires > 0
                    ? date('Y-m-d H:i', $token->expires) . ($token->isExpired($now) ? ' (expired)' : '')
                    : 'never',
                $token->lastUsed > 0 ? date('Y-m-d H:i', $token->lastUsed) : 'never',
                $token->crdate > 0 ? date('Y-m-d H:i', $token->crdate) : '—',
            ]);
        }
        $table->render();

        return Command::SUCCESS;
    }
}
