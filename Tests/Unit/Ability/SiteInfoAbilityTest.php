<?php

declare(strict_types=1);

namespace Webconsulting\Abilities\Tests\Unit\Ability;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Webconsulting\Abilities\Ability\SiteInfoAbility;
use Webconsulting\Abilities\Domain\AbilityDefinition;
use Webconsulting\Abilities\Domain\RiskTier;

/**
 * Executing SiteInfoAbility needs a booted TYPO3 (SiteFinder); its registry
 * metadata and contract are plain PHP and pinned here.
 */
final class SiteInfoAbilityTest extends TestCase
{
    #[Test]
    public function definitionDeclaresAReadOnlySystemAbility(): void
    {
        $definition = AbilityDefinition::fromClassName(SiteInfoAbility::class);

        self::assertSame('system/site-info', $definition->name);
        self::assertSame('system', $definition->category);
        self::assertSame(['system:read'], $definition->scopes);
        self::assertSame(RiskTier::Low, $definition->riskTier);
        self::assertTrue($definition->isReadOnly());
        self::assertTrue($definition->idempotent);
        self::assertFalse($definition->destructive);
        self::assertSame('ability_system_site-info', $definition->mcpToolName());
    }
}
