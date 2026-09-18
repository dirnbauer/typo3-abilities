<?php

declare(strict_types=1);

namespace Webconsulting\Abilities\Tests\Unit\Domain;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Webconsulting\Abilities\Domain\AbilityErrorCode;
use Webconsulting\Abilities\Domain\AbilityResult;

final class AbilityErrorCodeTest extends TestCase
{
    /**
     * @return iterable<string, array{string, int}>
     */
    public static function statuses(): iterable
    {
        yield 'invalid input' => ['ability_invalid_input', 400];
        yield 'invalid permissions' => ['ability_invalid_permissions', 403];
        yield 'policy denied' => ['ability_policy_denied', 403];
        yield 'not found' => ['ability_not_found', 404];
        yield 'review required' => ['ability_review_required', 409];
        yield 'invalid output' => ['ability_invalid_output', 500];
        yield 'cannot execute' => ['ability_cannot_execute', 500];
        yield 'unknown' => ['something_else', 500];
    }

    #[Test]
    #[DataProvider('statuses')]
    public function mapsCodesToHttpStatuses(string $code, int $status): void
    {
        self::assertSame($status, AbilityErrorCode::httpStatusFor($code));
    }

    #[Test]
    public function resultExposesCodeAndStatus(): void
    {
        $failure = AbilityResult::failure(AbilityErrorCode::ReviewRequired, 'needs a human');
        self::assertSame('ability_review_required', $failure->errorCode);
        self::assertSame(409, $failure->httpStatus());
        self::assertSame(200, AbilityResult::success('x')->httpStatus());
    }
}
