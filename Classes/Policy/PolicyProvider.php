<?php

declare(strict_types=1);

namespace Webconsulting\Abilities\Policy;

use TYPO3\CMS\Core\Core\Environment;

/**
 * Loads the site-wide abilities policy from config/abilities-policy.yaml
 * (project root, next to capability-policy.yaml). No file means allow-all —
 * the policy layer is opt-in; scope and permission checks always run.
 */
class PolicyProvider
{
    public const POLICY_FILE = 'config/abilities-policy.yaml';

    private ?AbilityPolicy $policy = null;

    public function __construct(
        private readonly ?string $policyFile = null,
    ) {
    }

    public function get(): AbilityPolicy
    {
        return $this->policy ??= AbilityPolicy::fromFile(
            $this->policyFile ?? Environment::getProjectPath() . '/' . self::POLICY_FILE,
        );
    }
}
