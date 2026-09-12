<?php

declare(strict_types=1);

namespace Webconsulting\Abilities\Tests\Unit\Http;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Webconsulting\Abilities\Http\RestConfiguration;

final class RestConfigurationTest extends TestCase
{
    #[Test]
    public function defaultsWhenNothingIsConfigured(): void
    {
        $configuration = RestConfiguration::fromArray([]);

        self::assertTrue($configuration->enabled);
        self::assertSame('/abilities/v1', $configuration->basePath);
        self::assertSame('', $configuration->corsOrigins);
    }

    #[Test]
    public function parsesExtensionSettingsDefensively(): void
    {
        $configuration = RestConfiguration::fromArray([
            'restEnabled' => '0',
            'restBasePath' => 'api/abilities/',
            'restCorsOrigins' => ' https://app.example ',
        ]);

        self::assertFalse($configuration->enabled);
        self::assertSame('/api/abilities', $configuration->basePath);
        self::assertSame('https://app.example', $configuration->corsOrigins);

        self::assertSame('/abilities/v1', RestConfiguration::fromArray(['restBasePath' => '/'])->basePath, 'root is never a base path');
        self::assertTrue(RestConfiguration::fromArray(['restEnabled' => 'yes'])->enabled);
    }
}
