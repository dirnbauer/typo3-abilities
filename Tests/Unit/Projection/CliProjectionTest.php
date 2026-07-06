<?php

declare(strict_types=1);

namespace Webconsulting\Abilities\Tests\Unit\Projection;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Webconsulting\Abilities\Execution\AbilityExecutor;
use Webconsulting\Abilities\Policy\PolicyProvider;
use Webconsulting\Abilities\Projection\Cli\DescribeAbilityCommand;
use Webconsulting\Abilities\Projection\Cli\ListAbilitiesCommand;
use Webconsulting\Abilities\Projection\Cli\RunAbilityCommand;
use Webconsulting\Abilities\Registry\AbilitiesRegistry;
use Webconsulting\Abilities\Tests\Fixtures\EchoAbility;
use Webconsulting\Abilities\Tests\Support\TypeNarrowing;
use Webconsulting\Abilities\Validation\SchemaValidator;

final class CliProjectionTest extends TestCase
{
    use TypeNarrowing;

    private AbilitiesRegistry $registry;

    private AbilityExecutor $executor;

    protected function setUp(): void
    {
        $this->registry = new AbilitiesRegistry([new EchoAbility()]);
        $this->executor = new AbilityExecutor(
            new SchemaValidator(),
            new PolicyProvider('/nonexistent/policy.yaml'),
        );
    }

    #[Test]
    public function listCommandRendersRegistryAsJson(): void
    {
        $tester = new CommandTester(new ListAbilitiesCommand($this->registry));

        self::assertSame(0, $tester->execute(['--json' => true]));
        $decoded = self::decodeJson($tester->getDisplay());
        self::assertCount(1, $decoded);
        $first = self::asArray($decoded[0]);
        self::assertSame('test/echo', $first['name']);
        self::assertSame('ability_test_echo', $first['mcpToolName']);
    }

    #[Test]
    public function describeCommandEmitsFullContract(): void
    {
        $tester = new CommandTester(new DescribeAbilityCommand($this->registry));

        self::assertSame(0, $tester->execute(['name' => 'test/echo']));
        $decoded = self::decodeJson($tester->getDisplay());
        self::assertSame('test/echo', $decoded['name']);
        self::assertSame('object', self::asArray($decoded['inputSchema'])['type']);
        self::assertSame(['echo'], self::asArray($decoded['outputSchema'])['required']);
    }

    #[Test]
    public function describeCommandFailsOnUnknownAbility(): void
    {
        $tester = new CommandTester(new DescribeAbilityCommand($this->registry));

        self::assertSame(1, $tester->execute(['name' => 'nope/nope']));
        self::assertStringContainsString('Unknown ability', $tester->getDisplay());
    }

    #[Test]
    public function runCommandExecutesThroughThePipeline(): void
    {
        $tester = new CommandTester(new RunAbilityCommand($this->registry, $this->executor));

        self::assertSame(0, $tester->execute(['name' => 'test/echo', '--input' => '{"message": "hi"}']));
        $decoded = self::decodeJson($tester->getDisplay());
        self::assertTrue($decoded['ok']);
        self::assertSame('hi', self::asArray($decoded['data'])['echo']);
    }

    #[Test]
    public function runCommandReportsPipelineFailuresWithNonZeroExit(): void
    {
        $tester = new CommandTester(new RunAbilityCommand($this->registry, $this->executor));

        self::assertSame(1, $tester->execute(['name' => 'test/echo', '--input' => '{}']));
        $decoded = self::decodeJson($tester->getDisplay());
        self::assertFalse($decoded['ok']);
        self::assertSame('invalid_input', $decoded['errorCode']);
    }

    #[Test]
    public function runCommandRejectsInvalidJsonInput(): void
    {
        $tester = new CommandTester(new RunAbilityCommand($this->registry, $this->executor));

        self::assertSame(2, $tester->execute(['name' => 'test/echo', '--input' => 'not json']));
        self::assertStringContainsString('not valid JSON', $tester->getDisplay());
    }
}
