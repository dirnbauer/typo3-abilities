<?php

declare(strict_types=1);

namespace Webconsulting\Abilities\Projection\Cli;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use Webconsulting\Abilities\Permission\BackendUserScopeResolver;
use Webconsulting\Abilities\Security\TokenService;

/**
 * Issues a REST bearer token bound to a backend user. The plaintext is
 * printed exactly once — only its hash is stored.
 */
#[AsCommand(
    name: 'abilities:token:create',
    description: 'Create a REST bearer token bound to a backend user (the plaintext is shown once)',
)]
final class TokenCreateCommand extends Command
{
    public function __construct(
        private readonly TokenService $tokenService,
        private readonly BackendUserScopeResolver $scopeResolver,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('user', 'u', InputOption::VALUE_REQUIRED, 'Backend username the token acts as')
            ->addOption('scopes', 's', InputOption::VALUE_REQUIRED, 'Comma-separated scopes, e.g. "system:read,news:write" ("*" = every scope the user holds)', '')
            ->addOption('name', null, InputOption::VALUE_REQUIRED, 'Descriptive token name, e.g. "n8n production"')
            ->addOption('expires', 'e', InputOption::VALUE_REQUIRED, 'Lifetime in days (omit for a non-expiring token)')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Print the result as JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $username = $input->getOption('user');
        $name = $input->getOption('name');
        if (!is_string($username) || $username === '' || !is_string($name) || trim($name) === '') {
            $output->writeln('<error>--user and --name are required.</error>');

            return Command::INVALID;
        }

        $user = $this->scopeResolver->findUserByUsername($username);
        if ($user === null) {
            $output->writeln(sprintf('<error>Backend user "%s" does not exist or is disabled.</error>', $username));

            return Command::INVALID;
        }

        $rawScopes = $input->getOption('scopes');
        $scopes = GeneralUtility::trimExplode(',', is_string($rawScopes) ? $rawScopes : '', true);

        $expiresAt = null;
        $expiresOption = $input->getOption('expires');
        if (is_string($expiresOption) && $expiresOption !== '') {
            if (!is_numeric($expiresOption) || (int)$expiresOption <= 0) {
                $output->writeln('<error>--expires must be a positive number of days.</error>');

                return Command::INVALID;
            }
            $expiresAt = time() + (int)$expiresOption * 86400;
        }

        $issued = $this->tokenService->create($name, (int)$user['uid'], $scopes, $expiresAt);

        $userScopes = $this->scopeResolver->resolveForUserRecord($user);
        $effective = BackendUserScopeResolver::intersect($issued->token->scopes, $userScopes);

        if ($input->getOption('json')) {
            $output->writeln((string)json_encode([
                ...$issued->token->toArray(),
                'username' => $user['username'] ?? $username,
                'effectiveScopes' => $effective,
                'token' => $issued->plaintext,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return Command::SUCCESS;
        }

        $output->writeln(sprintf(
            'Created token #%d "%s" for backend user "%s" (uid %d).',
            $issued->token->uid,
            $issued->token->name,
            is_string($user['username'] ?? null) ? $user['username'] : $username,
            $issued->token->backendUserUid,
        ));
        $output->writeln(sprintf('Token scopes:     %s', implode(', ', $issued->token->scopes) ?: '(none)'));
        $output->writeln(sprintf('Effective scopes: %s (token ∩ user)', implode(', ', $effective) ?: '(none)'));
        $output->writeln(sprintf(
            'Expires:          %s',
            $issued->token->expires > 0 ? date('c', $issued->token->expires) : 'never',
        ));
        $output->writeln('');
        $output->writeln('<info>' . $issued->plaintext . '</info>');
        $output->writeln('');
        $output->writeln('<comment>Store the token now — only its SHA-256 hash is kept and it cannot be shown again.</comment>');
        if ($effective === []) {
            $output->writeln('<comment>Warning: the effective scope list is empty; the token can discover abilities but not run any.</comment>');
        }

        return Command::SUCCESS;
    }
}
