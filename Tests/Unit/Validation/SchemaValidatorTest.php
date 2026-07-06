<?php

declare(strict_types=1);

namespace Webconsulting\Abilities\Tests\Unit\Validation;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Webconsulting\Abilities\Validation\SchemaValidator;

final class SchemaValidatorTest extends TestCase
{
    private SchemaValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new SchemaValidator();
    }

    #[Test]
    public function emptySchemaAcceptsAnything(): void
    {
        self::assertSame([], $this->validator->validate(['whatever' => true], []));
        self::assertSame([], $this->validator->validate(null, []));
    }

    /**
     * @return iterable<string, array{mixed, string, bool}>
     */
    public static function typeChecks(): iterable
    {
        yield 'string ok' => ['x', 'string', true];
        yield 'string vs int' => [1, 'string', false];
        yield 'integer ok' => [3, 'integer', true];
        yield 'integer vs float' => [3.5, 'integer', false];
        yield 'number accepts float' => [3.5, 'number', true];
        yield 'number accepts int' => [3, 'number', true];
        yield 'boolean ok' => [true, 'boolean', true];
        yield 'null ok' => [null, 'null', true];
        yield 'array ok' => [[1, 2], 'array', true];
        yield 'empty array is array' => [[], 'array', true];
        yield 'empty array is object' => [[], 'object', true];
        yield 'assoc is object' => [['a' => 1], 'object', true];
        yield 'assoc is not array' => [['a' => 1], 'array', false];
        yield 'list is not object' => [[1, 2], 'object', false];
    }

    #[Test]
    #[DataProvider('typeChecks')]
    public function checksTypes(mixed $value, string $type, bool $valid): void
    {
        $errors = $this->validator->validate($value, ['type' => $type]);

        self::assertSame($valid, $errors === [], implode('; ', $errors));
    }

    #[Test]
    public function acceptsUnionTypes(): void
    {
        $schema = ['type' => ['string', 'null']];

        self::assertSame([], $this->validator->validate('x', $schema));
        self::assertSame([], $this->validator->validate(null, $schema));
        self::assertNotSame([], $this->validator->validate(1, $schema));
    }

    #[Test]
    public function validatesObjectContract(): void
    {
        $schema = [
            'type' => 'object',
            'required' => ['message'],
            'additionalProperties' => false,
            'properties' => [
                'message' => ['type' => 'string', 'minLength' => 2, 'maxLength' => 5],
                'level' => ['type' => 'string', 'enum' => ['info', 'warn']],
            ],
        ];

        self::assertSame([], $this->validator->validate(['message' => 'abc', 'level' => 'info'], $schema));

        $errors = $this->validator->validate(['level' => 'nope', 'extra' => 1], $schema);
        $joined = implode('; ', $errors);
        self::assertStringContainsString('missing required property "message"', $joined);
        self::assertStringContainsString('enum', $joined);
        self::assertStringContainsString('unexpected additional property "extra"', $joined);
    }

    #[Test]
    public function validatesNestedArraysWithPaths(): void
    {
        $schema = [
            'type' => 'object',
            'properties' => [
                'items' => [
                    'type' => 'array',
                    'minItems' => 1,
                    'items' => [
                        'type' => 'object',
                        'required' => ['id'],
                        'properties' => ['id' => ['type' => 'integer', 'minimum' => 1]],
                    ],
                ],
            ],
        ];

        self::assertSame([], $this->validator->validate(['items' => [['id' => 1]]], $schema));

        $errors = $this->validator->validate(['items' => [['id' => 0], []]], $schema);
        $joined = implode('; ', $errors);
        self::assertStringContainsString('$.items[0].id: value is smaller than minimum 1', $joined);
        self::assertStringContainsString('$.items[1]: missing required property "id"', $joined);
    }

    #[Test]
    public function validatesStringPattern(): void
    {
        $schema = ['type' => 'string', 'pattern' => '^[a-z]+$'];

        self::assertSame([], $this->validator->validate('abc', $schema));
        self::assertNotSame([], $this->validator->validate('ABC', $schema));
    }

    #[Test]
    public function validatesNumericBounds(): void
    {
        $schema = ['type' => 'number', 'minimum' => 1, 'maximum' => 10];

        self::assertSame([], $this->validator->validate(5.5, $schema));
        self::assertNotSame([], $this->validator->validate(0, $schema));
        self::assertNotSame([], $this->validator->validate(11, $schema));
    }

    #[Test]
    public function enforcesMinItemsOnEmptyList(): void
    {
        $errors = $this->validator->validate([], ['type' => 'array', 'minItems' => 1]);

        self::assertStringContainsString('minItems', implode('; ', $errors));
    }

    #[Test]
    public function requiredFailsOnEmptyObject(): void
    {
        $errors = $this->validator->validate([], ['type' => 'object', 'required' => ['id']]);

        self::assertStringContainsString('missing required property "id"', implode('; ', $errors));
    }

    #[Test]
    public function appliesTopLevelDefaults(): void
    {
        $schema = [
            'type' => 'object',
            'properties' => [
                'repeat' => ['type' => 'integer', 'default' => 1],
                'message' => ['type' => 'string'],
            ],
        ];

        self::assertSame(
            ['message' => 'hi', 'repeat' => 1],
            $this->validator->applyDefaults(['message' => 'hi'], $schema),
        );
        self::assertSame(
            ['repeat' => 3],
            $this->validator->applyDefaults(['repeat' => 3], $schema),
        );
    }
}
