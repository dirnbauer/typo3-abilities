<?php

declare(strict_types=1);

namespace Webconsulting\Abilities\Tests\Fixtures;

use Webconsulting\Abilities\Attribute\AsAbility;
use Webconsulting\Abilities\Domain\ExecutionContext;
use Webconsulting\Abilities\Registry\AbstractAbility;

/** Exposed to the CLI only — must not appear on MCP or REST. */
#[AsAbility(
    name: 'test/hidden',
    title: 'Hidden',
    description: 'Not exposed to MCP or REST.',
    category: 'testing',
    expose: ['cli'],
)]
final class HiddenAbility extends AbstractAbility
{
    public function execute(array $input, ExecutionContext $context): mixed
    {
        return null;
    }
}
