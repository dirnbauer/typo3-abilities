<?php

declare(strict_types=1);

namespace Webconsulting\Abilities\Tests\Unit\Execution;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Webconsulting\Abilities\Domain\AbilityResult;
use Webconsulting\Abilities\Domain\ExecutionContext;
use Webconsulting\Abilities\Execution\AbilityExecutor;
use Webconsulting\Abilities\Policy\PolicyProvider;
use Webconsulting\Abilities\Tests\Fixtures\CallbackAbility;
use Webconsulting\Abilities\Tests\Fixtures\EchoAbility;
use Webconsulting\Abilities\Validation\SchemaValidator;

final class AbilityExecutorTest extends TestCase
{
    private string $policyFile;

    protected function setUp(): void
    {
        $this->policyFile = tempnam(sys_get_temp_dir(), 'abilities-policy-') . '.yaml';
    }

    protected function tearDown(): void
    {
        @unlink($this->policyFile);
    }

    private function executor(?string $policyYaml = null): AbilityExecutor
    {
        if ($policyYaml !== null) {
            file_put_contents($this->policyFile, $policyYaml);
            $provider = new PolicyProvider($this->policyFile);
        } else {
            $provider = new PolicyProvider('/nonexistent/abilities-policy.yaml');
        }

        return new AbilityExecutor(new SchemaValidator(), $provider);
    }

    #[Test]
    public function happyPathValidatesExecutesAndFillsDefaults(): void
    {
        $result = $this->executor()->execute(
            new EchoAbility(),
            ['message' => 'hi'],
            ExecutionContext::cli(),
        );

        self::assertTrue($result->ok);
        self::assertSame(['echo' => 'hi'], $result->data);
    }

    #[Test]
    public function defaultsReachTheAbility(): void
    {
        $result = $this->executor()->execute(
            new EchoAbility(),
            ['message' => 'ab', 'repeat' => 3],
            ExecutionContext::cli(),
        );

        self::assertTrue($result->ok);
        self::assertSame(['echo' => 'ababab'], $result->data);
    }

    #[Test]
    public function invalidInputNeverExecutes(): void
    {
        $executed = false;
        $ability = new CallbackAbility(
            onExecute: static function () use (&$executed): mixed {
                $executed = true;

                return null;
            },
            inputSchema: ['type' => 'object', 'required' => ['id'], 'properties' => ['id' => ['type' => 'integer']]],
        );

        $result = $this->executor()->execute($ability, [], ExecutionContext::cli());

        self::assertFalse($result->ok);
        self::assertSame(AbilityResult::ERROR_INVALID_INPUT, $result->errorCode);
        self::assertStringContainsString('missing required property "id"', (string)$result->error);
        self::assertFalse($executed, 'execute() must not run on invalid input');
    }

    #[Test]
    public function missingScopesDenyExecution(): void
    {
        $context = new ExecutionContext(ExecutionContext::SURFACE_REST, ['other:read']);

        $result = $this->executor()->execute(new EchoAbility(), ['message' => 'hi'], $context);

        self::assertFalse($result->ok);
        self::assertSame(AbilityResult::ERROR_PERMISSION_DENIED, $result->errorCode);
        self::assertStringContainsString('testing:read', (string)$result->error);
    }

    #[Test]
    public function permissionDenialReasonIsReported(): void
    {
        $ability = new CallbackAbility(
            onExecute: static fn(): mixed => null,
            onCheckPermission: static fn(): string => 'Backend user lacks table permissions.',
        );

        $result = $this->executor()->execute($ability, [], ExecutionContext::cli());

        self::assertFalse($result->ok);
        self::assertSame(AbilityResult::ERROR_PERMISSION_DENIED, $result->errorCode);
        self::assertSame('Backend user lacks table permissions.', $result->error);
    }

    #[Test]
    public function exceptionsBecomeExecutionErrors(): void
    {
        $ability = new CallbackAbility(
            onExecute: static fn(): mixed => throw new \RuntimeException('boom'),
        );

        $result = $this->executor()->execute($ability, [], ExecutionContext::cli());

        self::assertFalse($result->ok);
        self::assertSame(AbilityResult::ERROR_EXECUTION_ERROR, $result->errorCode);
        self::assertStringContainsString('RuntimeException', (string)$result->error);
        self::assertStringContainsString('boom', (string)$result->error);
    }

    #[Test]
    public function outputContractViolationIsReported(): void
    {
        $ability = new CallbackAbility(
            onExecute: static fn(): mixed => ['echo' => 42],
            outputSchema: ['type' => 'object', 'properties' => ['echo' => ['type' => 'string']]],
        );

        $result = $this->executor()->execute($ability, [], ExecutionContext::cli());

        self::assertFalse($result->ok);
        self::assertSame(AbilityResult::ERROR_INVALID_OUTPUT, $result->errorCode);
        self::assertStringContainsString('output contract', (string)$result->error);
    }

    #[Test]
    public function policyDenialShortCircuitsEverything(): void
    {
        $executed = false;
        $ability = new CallbackAbility(
            onExecute: static function () use (&$executed): mixed {
                $executed = true;

                return null;
            },
        );

        $result = $this->executor(<<<YAML
            policy:
              name: "test policy"
              deny:
                - "side-effect:database"
            YAML)->execute($ability, [], ExecutionContext::cli());

        self::assertFalse($result->ok);
        self::assertSame(AbilityResult::ERROR_POLICY_DENIED, $result->errorCode);
        self::assertFalse($executed, 'execute() must not run when policy denies');
    }

    #[Test]
    public function reviewRequiredPolicyHonorsApprovalFlag(): void
    {
        $yaml = <<<YAML
            policy:
              name: "review policy"
              review_required:
                - "risk:high"
            YAML;

        $denied = $this->executor($yaml)->execute(
            new CallbackAbility(static fn(): mixed => 'ran'),
            [],
            ExecutionContext::cli(),
        );
        self::assertSame(AbilityResult::ERROR_POLICY_DENIED, $denied->errorCode);

        $approved = $this->executor($yaml)->execute(
            new CallbackAbility(static fn(): mixed => 'ran'),
            [],
            ExecutionContext::cli(reviewApproved: true),
        );
        self::assertTrue($approved->ok);
        self::assertSame('ran', $approved->data);
    }

    #[Test]
    public function maxRiskTierPolicyBlocksHighRiskAbility(): void
    {
        $result = $this->executor(<<<YAML
            policy:
              name: "cap policy"
              max_risk_tier: "medium"
            YAML)->execute(new CallbackAbility(static fn(): mixed => null), [], ExecutionContext::cli());

        self::assertSame(AbilityResult::ERROR_POLICY_DENIED, $result->errorCode);

        $lowRisk = $this->executor(<<<YAML
            policy:
              name: "cap policy"
              max_risk_tier: "medium"
            YAML)->execute(new EchoAbility(), ['message' => 'ok'], ExecutionContext::cli());

        self::assertTrue($lowRisk->ok);
    }
}
