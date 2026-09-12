<?php

declare(strict_types=1);

namespace Webconsulting\Abilities\Tests\Fixtures;

use Webconsulting\Abilities\Attribute\AsAbility;
use Webconsulting\Abilities\Domain\ExecutionContext;
use Webconsulting\Abilities\Domain\RiskTier;
use Webconsulting\Abilities\Registry\AbstractAbility;

/** A non-destructive write: side effects, so REST runs it via POST. */
#[AsAbility(
    name: 'test/write',
    title: 'Write',
    description: 'Stores a value.',
    category: 'testing',
    scopes: ['testing:write'],
    riskTier: RiskTier::Medium,
    sideEffects: ['database:write'],
    instructions: 'Send the value to store.',
)]
final class WriteAbility extends AbstractAbility
{
    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'required' => ['value'],
            'properties' => ['value' => ['type' => 'string']],
            'additionalProperties' => false,
        ];
    }

    public function execute(array $input, ExecutionContext $context): mixed
    {
        return ['stored' => $input['value'] ?? null];
    }
}
