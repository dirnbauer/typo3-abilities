<?php

declare(strict_types=1);

namespace Webconsulting\Abilities\Tests\Unit\Domain;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Webconsulting\Abilities\Domain\ExecutionContext;

final class ExecutionContextTest extends TestCase
{
    #[Test]
    public function trustedContextIsMissingNoScopes(): void
    {
        $context = ExecutionContext::cli();

        self::assertNull($context->grantedScopes);
        self::assertSame([], $context->missingScopes(['news:write', 'system:read']));
        self::assertTrue($context->hasScope('anything:at-all'));
    }

    #[Test]
    public function explicitGrantsAreEnforced(): void
    {
        $context = new ExecutionContext(ExecutionContext::SURFACE_REST, ['news:read']);

        self::assertTrue($context->hasScope('news:read'));
        self::assertFalse($context->hasScope('news:write'));
        self::assertSame(['news:write'], $context->missingScopes(['news:read', 'news:write']));
    }

    #[Test]
    public function emptyGrantListDeniesEveryScope(): void
    {
        $context = new ExecutionContext(ExecutionContext::SURFACE_REST, []);

        self::assertSame(['system:read'], $context->missingScopes(['system:read']));
    }

    #[Test]
    public function factoriesSetSurfaceAndApproval(): void
    {
        self::assertSame(ExecutionContext::SURFACE_MCP, ExecutionContext::mcp()->surface);
        self::assertFalse(ExecutionContext::mcp()->reviewApproved);
        self::assertTrue(ExecutionContext::cli(reviewApproved: true)->reviewApproved);
    }
}
