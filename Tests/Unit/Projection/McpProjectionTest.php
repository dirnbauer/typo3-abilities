<?php

declare(strict_types=1);

namespace Webconsulting\Abilities\Tests\Unit\Projection;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Webconsulting\Abilities\Domain\AbilityResult;
use Webconsulting\Abilities\Domain\ExecutionContext;
use Webconsulting\Abilities\Execution\AbilityExecutor;
use Webconsulting\Abilities\Policy\PolicyProvider;
use Webconsulting\Abilities\Projection\Mcp\McpProjection;
use Webconsulting\Abilities\Projection\Mcp\McpToolDescriptor;
use Webconsulting\Abilities\Registry\AbilitiesRegistry;
use Webconsulting\Abilities\Tests\Fixtures\CallbackAbility;
use Webconsulting\Abilities\Tests\Fixtures\EchoAbility;
use Webconsulting\Abilities\Tests\Fixtures\HiddenAbility;
use Webconsulting\Abilities\Tests\Fixtures\WriteAbility;
use Webconsulting\Abilities\Validation\SchemaValidator;

final class McpProjectionTest extends TestCase
{
    private McpProjection $projection;

    protected function setUp(): void
    {
        $registry = new AbilitiesRegistry([
            new EchoAbility(),
            new HiddenAbility(),
            new WriteAbility(),
            new CallbackAbility(static fn(): mixed => 'done'),
        ]);
        $this->projection = new McpProjection(
            $registry,
            new AbilityExecutor(new SchemaValidator(), new PolicyProvider('/nonexistent/policy.yaml')),
        );
    }

    #[Test]
    public function descriptorsCoverOnlyMcpExposedAbilitiesSortedByName(): void
    {
        $descriptors = [...$this->projection->descriptors()];

        self::assertContainsOnlyInstancesOf(McpToolDescriptor::class, $descriptors);
        self::assertSame(
            ['ability_test_callback', 'ability_test_echo', 'ability_test_write'],
            array_map(static fn(McpToolDescriptor $descriptor): string => $descriptor->name, $descriptors),
        );
    }

    #[Test]
    public function descriptorCarriesContractAnnotationsAndInstructions(): void
    {
        $echo = $this->projection->descriptor('ability_test_echo');
        self::assertNotNull($echo);
        self::assertStringContainsString('Echo — Returns the given message', $echo->description);
        self::assertSame('object', $echo->inputSchema['type']);
        self::assertSame(
            ['title' => 'Echo', 'readOnlyHint' => true, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false],
            $echo->annotations,
        );
        self::assertSame('test/echo', $echo->definition->name);

        $write = $this->projection->descriptor('ability_test_write');
        self::assertNotNull($write);
        self::assertStringEndsWith("\n\nSend the value to store.", $write->description);
        self::assertFalse($write->annotations['readOnlyHint']);

        $destructive = $this->projection->descriptor('ability_test_callback');
        self::assertNotNull($destructive);
        self::assertTrue($destructive->annotations['destructiveHint']);
        self::assertInstanceOf(\stdClass::class, $destructive->inputSchema['properties'], 'empty schema becomes an object schema');
        self::assertSame(['name', 'description', 'inputSchema', 'annotations'], array_keys($destructive->toArray()));
    }

    #[Test]
    public function resolvesToolNamesBackToAbilities(): void
    {
        self::assertSame('test/echo', $this->projection->resolveAbilityName('ability_test_echo'));
        self::assertTrue($this->projection->has('ability_test_echo'));
        self::assertNull($this->projection->resolveAbilityName('ability_test_hidden'), 'not exposed to mcp');
        self::assertNull($this->projection->resolveAbilityName('ability_nope_nope'));
        self::assertNull($this->projection->resolveAbilityName('GetPage'));
        self::assertNull($this->projection->resolveAbilityName('ability_broken'));
        self::assertNull($this->projection->descriptor('ability_test_hidden'));
    }

    #[Test]
    public function executesThroughTheGovernedPipeline(): void
    {
        $result = $this->projection->execute('ability_test_echo', ['message' => 'hi'], ExecutionContext::mcp());
        self::assertTrue($result->ok);
        self::assertSame(['echo' => 'hi'], $result->data);

        $invalid = $this->projection->execute('ability_test_echo', [], ExecutionContext::mcp());
        self::assertSame(AbilityResult::ERROR_INVALID_INPUT, $invalid->errorCode);

        $unknown = $this->projection->execute('ability_test_hidden', [], ExecutionContext::mcp());
        self::assertFalse($unknown->ok);
        self::assertSame(AbilityResult::ERROR_NOT_FOUND, $unknown->errorCode);
    }
}
