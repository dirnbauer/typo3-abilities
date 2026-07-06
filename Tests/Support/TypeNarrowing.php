<?php

declare(strict_types=1);

namespace Webconsulting\Abilities\Tests\Support;

use PHPUnit\Framework\Assert;

/**
 * Assertion-backed narrowing for JSON-decoded structures under
 * PHPStan level max: each accessor asserts the runtime type and
 * returns it narrowed.
 */
trait TypeNarrowing
{
    /**
     * @return array<mixed>
     */
    private static function asArray(mixed $value): array
    {
        Assert::assertIsArray($value);

        return $value;
    }

    private static function asString(mixed $value): string
    {
        Assert::assertIsString($value);

        return $value;
    }

    /**
     * @return array<mixed>
     */
    private static function decodeJson(string $json): array
    {
        return self::asArray(json_decode($json, true, 512, JSON_THROW_ON_ERROR));
    }
}
