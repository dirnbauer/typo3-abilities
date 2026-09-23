<?php

declare(strict_types=1);

namespace Webconsulting\Abilities\Http;

use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Core\Http\JsonResponse;
use TYPO3\CMS\Core\Http\Response;
use Webconsulting\Abilities\Domain\AbilityResult;

/**
 * JSON envelopes of the REST projection:
 *   {"ok": true,  "data": ...}
 *   {"ok": false, "code": "...", "message": "..."}
 * Every response is uncacheable and nosniff.
 */
final class RestResponseFactory
{
    public const string ERROR_UNAUTHORIZED = 'rest_unauthorized';
    public const string ERROR_NOT_FOUND = 'rest_not_found';
    public const string ERROR_ABILITY_NOT_FOUND = 'rest_ability_not_found';
    public const string ERROR_ABILITY_INVALID_METHOD = 'rest_ability_invalid_method';
    public const string ERROR_ABILITY_CANNOT_EXECUTE = 'rest_ability_cannot_execute';
    public const string ERROR_CATEGORY_NOT_FOUND = 'rest_ability_category_not_found';
    public const string ERROR_INVALID_METHOD = 'rest_invalid_method';

    /**
     * @param array<string, string> $headers
     */
    public function success(mixed $data, int $status = 200, array $headers = []): ResponseInterface
    {
        return new JsonResponse(['ok' => true, 'data' => $data], $status, $this->headers($headers));
    }

    /**
     * @param array<string, string> $headers
     */
    public function error(string $code, string $message, int $status, array $headers = []): ResponseInterface
    {
        return new JsonResponse(['ok' => false, 'code' => $code, 'message' => $message], $status, $this->headers($headers));
    }

    public function fromResult(AbilityResult $result): ResponseInterface
    {
        if ($result->ok) {
            return $this->success($result->data);
        }

        return $this->error((string)$result->errorCode, (string)$result->error, $result->httpStatus());
    }

    /**
     * @param array<string, string> $headers
     */
    public function empty(int $status = 204, array $headers = []): ResponseInterface
    {
        $response = new Response('php://temp', $status);
        foreach ($this->headers($headers) as $name => $value) {
            $response = $response->withHeader($name, $value);
        }

        return $response;
    }

    /**
     * @param array<string, string> $headers
     * @return array<string, string>
     */
    private function headers(array $headers): array
    {
        return [
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'no-store',
            ...$headers,
        ];
    }
}
