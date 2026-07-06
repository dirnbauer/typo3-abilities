<?php

declare(strict_types=1);

namespace Webconsulting\Abilities\Registry;

use Webconsulting\Abilities\Domain\ExecutionContext;

/**
 * Convenience base class for abilities. Scope checks and policy gating are
 * handled by the executor; override checkPermission() only for checks that
 * need the concrete input or TYPO3 user context (backend user, workspace).
 */
abstract class AbstractAbility implements AbilityInterface
{
    public function getInputSchema(): array
    {
        return [];
    }

    public function getOutputSchema(): array
    {
        return [];
    }

    public function checkPermission(array $input, ExecutionContext $context): bool|string
    {
        return true;
    }
}
