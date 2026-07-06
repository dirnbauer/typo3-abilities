<?php

declare(strict_types=1);

namespace Webconsulting\Abilities\Tests\Fixtures;

use Webconsulting\Abilities\Attribute\AsAbility;
use Webconsulting\Abilities\Domain\ExecutionContext;
use Webconsulting\Abilities\Domain\RiskTier;
use Webconsulting\Abilities\Registry\AbilityInterface;

/**
 * Configurable ability for executor pipeline tests: behavior is injected
 * as closures, the registry metadata is fixed by the attribute.
 */
#[AsAbility(
    name: 'test/callback',
    title: 'Callback',
    description: 'Test double whose behavior is provided as closures.',
    category: 'testing',
    scopes: ['testing:write'],
    riskTier: RiskTier::High,
    sideEffects: ['database:write'],
    destructive: true,
)]
final class CallbackAbility implements AbilityInterface
{
    /**
     * @param array<string, mixed> $inputSchema
     * @param array<string, mixed> $outputSchema
     * @param \Closure(array<string, mixed>, ExecutionContext): mixed $onExecute
     * @param \Closure(array<string, mixed>, ExecutionContext): (bool|string)|null $onCheckPermission
     */
    public function __construct(
        private readonly \Closure $onExecute,
        private readonly ?\Closure $onCheckPermission = null,
        private readonly array $inputSchema = [],
        private readonly array $outputSchema = [],
    ) {
    }

    public function getInputSchema(): array
    {
        return $this->inputSchema;
    }

    public function getOutputSchema(): array
    {
        return $this->outputSchema;
    }

    public function checkPermission(array $input, ExecutionContext $context): bool|string
    {
        return $this->onCheckPermission === null ? true : ($this->onCheckPermission)($input, $context);
    }

    public function execute(array $input, ExecutionContext $context): mixed
    {
        return ($this->onExecute)($input, $context);
    }
}
