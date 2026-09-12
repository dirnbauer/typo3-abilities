<?php

declare(strict_types=1);

namespace Webconsulting\Abilities\Tests\Unit\Skills;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Webconsulting\Abilities\Policy\PolicyProvider;
use Webconsulting\Abilities\Registry\AbilitiesRegistry;
use Webconsulting\Abilities\Skills\SkillAbilityContract;
use Webconsulting\Abilities\Tests\Fixtures\CallbackAbility;
use Webconsulting\Abilities\Tests\Fixtures\EchoAbility;
use Webconsulting\Abilities\Tests\Fixtures\HiddenAbility;
use Webconsulting\Abilities\Tests\Fixtures\WriteAbility;

final class SkillAbilityContractTest extends TestCase
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

    private function contract(?string $policyYaml = null): SkillAbilityContract
    {
        if ($policyYaml !== null) {
            file_put_contents($this->policyFile, $policyYaml);
        }
        $registry = new AbilitiesRegistry([
            new EchoAbility(),
            new HiddenAbility(),
            new WriteAbility(),
            new CallbackAbility(static fn(): mixed => null),
        ]);

        return new SkillAbilityContract($registry, new PolicyProvider($policyYaml === null ? '/nonexistent/policy.yaml' : $this->policyFile));
    }

    #[Test]
    public function resolvesAbilityNamesToClientToolIds(): void
    {
        $resolved = $this->contract()->resolveMcpToolNames(['test/echo', 'test/write', 'nope/nope', 'test/echo']);

        self::assertSame([
            'test/echo' => 'mcp__typo3__ability_test_echo',
            'test/write' => 'mcp__typo3__ability_test_write',
        ], $resolved);
    }

    #[Test]
    public function validPolicyFreeDeclarationHasNoFindings(): void
    {
        self::assertSame([], $this->contract()->validate(['test/echo', 'test/write']));
    }

    #[Test]
    public function reportsMissingNotExposedDeniedAndReviewRequired(): void
    {
        $findings = $this->contract(<<<YAML
            policy:
              name: "skills policy"
              deny:
                - "test/write"
              review_required:
                - "risk:high"
            YAML)->validate(['nope/nope', 'test/hidden', 'test/write', 'test/callback', 'test/echo']);

        self::assertSame(
            [
                ['nope/nope', SkillAbilityContract::FINDING_MISSING],
                ['test/hidden', SkillAbilityContract::FINDING_NOT_EXPOSED],
                ['test/write', SkillAbilityContract::FINDING_POLICY_DENIED],
                ['test/callback', SkillAbilityContract::FINDING_REVIEW_REQUIRED],
            ],
            array_map(static fn(array $finding): array => [$finding['ability'], $finding['code']], $findings),
        );
        self::assertStringContainsString('not registered', $findings[0]['message']);
        self::assertStringContainsString('expose: cli', $findings[1]['message']);
    }
}
