<?php

declare(strict_types=1);

namespace Webconsulting\Abilities\Tests\Unit\Http;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Webconsulting\Abilities\Domain\AbilityErrorCode;
use Webconsulting\Abilities\Domain\AbilityResult;
use Webconsulting\Abilities\Http\RestResponseFactory;
use Webconsulting\Abilities\Tests\Support\TypeNarrowing;

final class RestResponseFactoryTest extends TestCase
{
    use TypeNarrowing;

    #[Test]
    public function successEnvelopeAndSecurityHeaders(): void
    {
        $response = new RestResponseFactory()->success(['a' => 1], 200, ['X-Total' => '3']);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('nosniff', $response->getHeaderLine('X-Content-Type-Options'));
        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
        self::assertSame('3', $response->getHeaderLine('X-Total'));
        self::assertStringStartsWith('application/json', $response->getHeaderLine('Content-Type'));
        self::assertSame(['ok' => true, 'data' => ['a' => 1]], self::decodeJson((string)$response->getBody()));
    }

    #[Test]
    public function errorEnvelope(): void
    {
        $response = new RestResponseFactory()->error('rest_ability_not_found', 'gone', 404, ['Allow' => 'GET']);

        self::assertSame(404, $response->getStatusCode());
        self::assertSame('GET', $response->getHeaderLine('Allow'));
        self::assertSame(['ok' => false, 'code' => 'rest_ability_not_found', 'message' => 'gone'], self::decodeJson((string)$response->getBody()));
    }

    #[Test]
    public function resultsMapToStatusesAndCodes(): void
    {
        $factory = new RestResponseFactory();

        $ok = $factory->fromResult(AbilityResult::success(['x' => true]));
        self::assertSame(200, $ok->getStatusCode());
        self::assertSame(['ok' => true, 'data' => ['x' => true]], self::decodeJson((string)$ok->getBody()));

        foreach ([
            [AbilityErrorCode::InvalidInput, 400],
            [AbilityErrorCode::InvalidPermissions, 403],
            [AbilityErrorCode::PolicyDenied, 403],
            [AbilityErrorCode::ReviewRequired, 409],
            [AbilityErrorCode::InvalidOutput, 500],
            [AbilityErrorCode::CannotExecute, 500],
        ] as [$code, $status]) {
            $response = $factory->fromResult(AbilityResult::failure($code, 'why'));
            self::assertSame($status, $response->getStatusCode(), $code->value);
            self::assertSame(['ok' => false, 'code' => $code->value, 'message' => 'why'], self::decodeJson((string)$response->getBody()));
        }
    }

    #[Test]
    public function emptyResponseForPreflight(): void
    {
        $response = new RestResponseFactory()->empty();

        self::assertSame(204, $response->getStatusCode());
        self::assertSame('', (string)$response->getBody());
        self::assertSame('nosniff', $response->getHeaderLine('X-Content-Type-Options'));
    }
}
