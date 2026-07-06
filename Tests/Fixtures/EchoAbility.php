<?php

declare(strict_types=1);

namespace Webconsulting\Abilities\Tests\Fixtures;

use Webconsulting\Abilities\Attribute\AsAbility;
use Webconsulting\Abilities\Domain\ExecutionContext;
use Webconsulting\Abilities\Domain\RiskTier;
use Webconsulting\Abilities\Registry\AbstractAbility;

#[AsAbility(
    name: 'test/echo',
    title: 'Echo',
    description: 'Returns the given message, optionally repeated.',
    category: 'testing',
    scopes: ['testing:read'],
    riskTier: RiskTier::Low,
    idempotent: true,
)]
final class EchoAbility extends AbstractAbility
{
    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'required' => ['message'],
            'additionalProperties' => false,
            'properties' => [
                'message' => ['type' => 'string', 'minLength' => 1],
                'repeat' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 5, 'default' => 1],
            ],
        ];
    }

    public function getOutputSchema(): array
    {
        return [
            'type' => 'object',
            'required' => ['echo'],
            'properties' => [
                'echo' => ['type' => 'string'],
            ],
        ];
    }

    public function execute(array $input, ExecutionContext $context): mixed
    {
        $message = $input['message'] ?? '';
        $repeat = $input['repeat'] ?? 1;

        return [
            'echo' => str_repeat(is_string($message) ? $message : '', is_int($repeat) ? $repeat : 1),
        ];
    }
}
