<?php

declare(strict_types=1);

namespace Webconsulting\Abilities\Tests\Unit\Policy;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Webconsulting\Abilities\Domain\AbilityDefinition;
use Webconsulting\Abilities\Domain\ExecutionContext;
use Webconsulting\Abilities\Policy\AbilityPolicy;
use Webconsulting\Abilities\Tests\Fixtures\CallbackAbility;
use Webconsulting\Abilities\Tests\Fixtures\EchoAbility;

final class AbilityPolicyTest extends TestCase
{
    private AbilityDefinition $echo;

    private AbilityDefinition $callback;

    protected function setUp(): void
    {
        // echo: test/echo, low risk, scopes [testing:read], no side effects
        $this->echo = AbilityDefinition::fromInstance(new EchoAbility());
        // callback: test/callback, high risk, scopes [testing:write], side effects [database:write]
        $this->callback = AbilityDefinition::fromInstance(new CallbackAbility(static fn(): mixed => null));
    }

    #[Test]
    public function allowAllAllowsEverything(): void
    {
        $decision = AbilityPolicy::allowAll()->decide($this->callback, ExecutionContext::cli());

        self::assertTrue($decision->allowed);
    }

    #[Test]
    public function missingFileMeansAllowAll(): void
    {
        $policy = AbilityPolicy::fromFile('/definitely/not/a/file.yaml');

        self::assertTrue($policy->decide($this->callback, ExecutionContext::cli())->allowed);
    }

    /**
     * @return iterable<string, array{string, bool}> rule, matches echo (test/echo, low, testing:read, read-only)
     */
    public static function denyRules(): iterable
    {
        yield 'exact name' => ['test/echo', true];
        yield 'other name' => ['test/other', false];
        yield 'namespace wildcard' => ['test/*', true];
        yield 'other namespace wildcard' => ['prod/*', false];
        yield 'global wildcard' => ['*', true];
        yield 'risk match' => ['risk:low', true];
        yield 'risk mismatch' => ['risk:high', false];
        yield 'scope exact' => ['scope:testing:read', true];
        yield 'scope prefix' => ['scope:testing', true];
        yield 'scope mismatch' => ['scope:news:write', false];
        yield 'side effect no match on read-only' => ['side-effect:database', false];
    }

    #[Test]
    #[DataProvider('denyRules')]
    public function denyRuleMatching(string $rule, bool $shouldDeny): void
    {
        $policy = AbilityPolicy::fromArray(['policy' => ['name' => 'test', 'deny' => [$rule]]]);

        $decision = $policy->decide($this->echo, ExecutionContext::cli());

        self::assertSame(!$shouldDeny, $decision->allowed);
        if ($shouldDeny) {
            self::assertNotNull($decision->reason);
            self::assertStringContainsString($rule, $decision->reason);
        }
    }

    #[Test]
    public function sideEffectRuleMatchesPrefix(): void
    {
        $policy = AbilityPolicy::fromArray(['policy' => ['deny' => ['side-effect:database']]]);

        self::assertFalse($policy->decide($this->callback, ExecutionContext::cli())->allowed);
    }

    #[Test]
    public function maxRiskTierCapsExecution(): void
    {
        $policy = AbilityPolicy::fromArray(['policy' => ['max_risk_tier' => 'medium']]);

        self::assertTrue($policy->decide($this->echo, ExecutionContext::cli())->allowed);
        $decision = $policy->decide($this->callback, ExecutionContext::cli());
        self::assertFalse($decision->allowed);
        self::assertStringContainsString('risk tier', (string)$decision->reason);
    }

    #[Test]
    public function invalidMaxRiskTierThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionCode(7480291006);

        AbilityPolicy::fromArray(['policy' => ['max_risk_tier' => 'extreme']]);
    }

    #[Test]
    public function reviewRequiredBlocksWithoutApproval(): void
    {
        $policy = AbilityPolicy::fromArray(['policy' => ['review_required' => ['risk:high']]]);

        $denied = $policy->decide($this->callback, ExecutionContext::cli());
        self::assertFalse($denied->allowed);
        self::assertStringContainsString('review', (string)$denied->reason);

        $approved = $policy->decide($this->callback, ExecutionContext::cli(reviewApproved: true));
        self::assertTrue($approved->allowed);
    }

    #[Test]
    public function denyOutranksReviewApproval(): void
    {
        $policy = AbilityPolicy::fromArray(['policy' => [
            'deny' => ['test/callback'],
            'review_required' => ['test/callback'],
        ]]);

        $decision = $policy->decide($this->callback, ExecutionContext::cli(reviewApproved: true));

        self::assertFalse($decision->allowed);
        self::assertStringContainsString('denied', (string)$decision->reason);
    }
}
