<?php

declare(strict_types=1);

namespace Webconsulting\Abilities\Tests\Unit\Projection;

use Mcp\Types\TextContent;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Webconsulting\Abilities\Execution\AbilityExecutor;
use Webconsulting\Abilities\Policy\PolicyProvider;
use Webconsulting\Abilities\Projection\Mcp\AbilityMcpTool;
use Webconsulting\Abilities\Tests\Fixtures\CallbackAbility;
use Webconsulting\Abilities\Tests\Fixtures\EchoAbility;
use Webconsulting\Abilities\Tests\Support\TypeNarrowing;
use Webconsulting\Abilities\Validation\SchemaValidator;

final class AbilityMcpToolTest extends TestCase
{
    use TypeNarrowing;

    private function tool(EchoAbility|CallbackAbility $ability): AbilityMcpTool
    {
        return new AbilityMcpTool(
            $ability,
            new AbilityExecutor(new SchemaValidator(), new PolicyProvider('/nonexistent/policy.yaml')),
        );
    }

    #[Test]
    public function projectsAbilityNameAsMcpToolName(): void
    {
        self::assertSame('ability_test_echo', $this->tool(new EchoAbility())->getName());
    }

    #[Test]
    public function schemaCarriesContractAndAnnotations(): void
    {
        $schema = $this->tool(new EchoAbility())->getSchema();

        self::assertStringContainsString('Echo — Returns the given message', self::asString($schema['description']));

        $inputSchema = self::asArray($schema['inputSchema']);
        self::assertSame('object', $inputSchema['type']);
        self::assertArrayHasKey('message', self::asArray($inputSchema['properties']));

        $annotations = self::asArray($schema['annotations']);
        self::assertTrue($annotations['readOnlyHint']);
        self::assertTrue($annotations['idempotentHint']);
        self::assertFalse($annotations['destructiveHint']);
        self::assertFalse($annotations['openWorldHint']);
    }

    #[Test]
    public function destructiveAbilityGetsHonestAnnotations(): void
    {
        $schema = $this->tool(new CallbackAbility(static fn(): mixed => null))->getSchema();

        $annotations = self::asArray($schema['annotations']);
        self::assertFalse($annotations['readOnlyHint']);
        self::assertTrue($annotations['destructiveHint']);
    }

    #[Test]
    public function emptyInputSchemaBecomesAnObjectSchemaForMcp(): void
    {
        $schema = $this->tool(new CallbackAbility(static fn(): mixed => null))->getSchema();

        $inputSchema = self::asArray($schema['inputSchema']);
        self::assertSame('object', $inputSchema['type']);
        self::assertInstanceOf(\stdClass::class, $inputSchema['properties']);
    }

    #[Test]
    public function executeReturnsResultEnvelopeAsJson(): void
    {
        $result = $this->tool(new EchoAbility())->execute(['message' => 'hi']);

        self::assertFalse($result->isError);
        self::assertInstanceOf(TextContent::class, $result->content[0]);
        $decoded = self::decodeJson($result->content[0]->text);
        self::assertTrue($decoded['ok']);
        self::assertSame(['echo' => 'hi'], $decoded['data']);
    }

    #[Test]
    public function failuresAreMarkedAsMcpErrors(): void
    {
        $result = $this->tool(new EchoAbility())->execute([]);

        self::assertTrue($result->isError);
        self::assertInstanceOf(TextContent::class, $result->content[0]);
        $decoded = self::decodeJson($result->content[0]->text);
        self::assertFalse($decoded['ok']);
        self::assertSame('invalid_input', $decoded['errorCode']);
    }
}
