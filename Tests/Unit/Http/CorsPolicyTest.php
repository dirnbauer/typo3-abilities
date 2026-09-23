<?php

declare(strict_types=1);

namespace Webconsulting\Abilities\Tests\Unit\Http;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Http\Response;
use TYPO3\CMS\Core\Http\ServerRequest;
use Webconsulting\Abilities\Http\CorsPolicy;

final class CorsPolicyTest extends TestCase
{
    #[Test]
    public function emptyConfigurationEmitsNothing(): void
    {
        $policy = CorsPolicy::fromString('');
        $request = new ServerRequest('http://localhost/abilities/v1/abilities')->withHeader('Origin', 'https://app.example');

        self::assertFalse($policy->isEnabled());
        self::assertSame([], $policy->apply($request, new Response())->getHeaders());
        self::assertFalse($policy->preflight($request, new Response())->hasHeader('Access-Control-Allow-Methods'));
    }

    #[Test]
    public function allowListEchoesTheMatchingOriginOnly(): void
    {
        $policy = CorsPolicy::fromString(' https://app.example/, https://other.example ');
        self::assertSame(['https://app.example', 'https://other.example'], $policy->allowedOrigins);

        $allowed = new ServerRequest('http://localhost/x')->withHeader('Origin', 'https://APP.example');
        $response = $policy->apply($allowed, new Response());
        self::assertSame('https://APP.example', $response->getHeaderLine('Access-Control-Allow-Origin'));
        self::assertSame('Origin', $response->getHeaderLine('Vary'));
        self::assertStringContainsString('X-Total', $response->getHeaderLine('Access-Control-Expose-Headers'));

        $denied = new ServerRequest('http://localhost/x')->withHeader('Origin', 'https://evil.example');
        self::assertFalse($policy->apply($denied, new Response())->hasHeader('Access-Control-Allow-Origin'));
        self::assertFalse($policy->apply(new ServerRequest('http://localhost/x'), new Response())->hasHeader('Access-Control-Allow-Origin'));
    }

    #[Test]
    public function wildcardAndPreflight(): void
    {
        $policy = CorsPolicy::fromString('*');
        $request = new ServerRequest('http://localhost/x', 'OPTIONS')->withHeader('Origin', 'https://any.example');

        $response = $policy->preflight($request, new Response());

        self::assertSame('*', $response->getHeaderLine('Access-Control-Allow-Origin'));
        self::assertSame('GET, POST, DELETE, OPTIONS', $response->getHeaderLine('Access-Control-Allow-Methods'));
        self::assertStringContainsString('Authorization', $response->getHeaderLine('Access-Control-Allow-Headers'));
        self::assertSame('600', $response->getHeaderLine('Access-Control-Max-Age'));
    }
}
