<?php

declare(strict_types=1);

namespace Webconsulting\Abilities\Http;

use Psr\Http\Message\ServerRequestInterface;
use Webconsulting\Abilities\Validation\SchemaValidator;

/**
 * Turns an HTTP request (or a decoded webhook payload) into an ability
 * input object:
 *  - GET/DELETE: ?input=<json object>, or plain query parameters mapped onto
 *    the top-level properties of the input schema and coerced to the
 *    declared scalar types (everything arrives as a string)
 *  - POST/DELETE: JSON body {"input": {...}} (or a bare JSON object)
 *
 * Throws InvalidRestInputException for malformed input; the caller maps it
 * to 400 ability_invalid_input.
 */
final class RestInputMapper
{
    public function __construct(
        private readonly SchemaValidator $validator = new SchemaValidator(),
    ) {}

    /**
     * @param array<string, mixed> $inputSchema
     * @return array<string, mixed>
     */
    public function fromRequest(ServerRequestInterface $request, array $inputSchema): array
    {
        $method = strtoupper($request->getMethod());
        $query = $request->getQueryParams();

        if ($method === 'GET') {
            return $this->fromQuery($query, $inputSchema);
        }

        $body = trim((string)$request->getBody());
        if ($body === '' && is_array($request->getParsedBody()) && $request->getParsedBody() !== []) {
            return $this->fromPayload($request->getParsedBody());
        }
        if ($body !== '') {
            return $this->fromBody($body);
        }

        return $this->fromQuery($query, $inputSchema);
    }

    /**
     * @param array<mixed> $query
     * @param array<string, mixed> $inputSchema
     * @return array<string, mixed>
     */
    public function fromQuery(array $query, array $inputSchema): array
    {
        if (isset($query['input'])) {
            if (!is_string($query['input'])) {
                throw new InvalidRestInputException('The "input" query parameter must be a JSON object string.');
            }

            return $this->fromBody($query['input']);
        }

        $properties = is_array($inputSchema['properties'] ?? null) ? $inputSchema['properties'] : [];
        $input = [];
        foreach ($properties as $property => $propertySchema) {
            if (is_string($property) && array_key_exists($property, $query)) {
                $input[$property] = $query[$property];
            }
        }

        return $this->validator->coerce($input, $inputSchema);
    }

    /**
     * @return array<string, mixed>
     */
    public function fromBody(string $json): array
    {
        try {
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new InvalidRestInputException('Request body is not valid JSON: ' . $exception->getMessage());
        }
        if (!is_array($decoded)) {
            throw new InvalidRestInputException('Request body must be a JSON object.');
        }

        return $this->fromPayload($decoded);
    }

    /**
     * A decoded JSON payload: {"input": {...}} is unwrapped, a bare object is
     * used as-is, a list is refused.
     *
     * @param array<mixed> $decoded
     * @return array<string, mixed>
     */
    public function fromPayload(array $decoded): array
    {
        if (array_key_exists('input', $decoded)) {
            $decoded = $decoded['input'];
            if (!is_array($decoded)) {
                throw new InvalidRestInputException('"input" must be a JSON object.');
            }
        }
        if ($decoded !== [] && array_is_list($decoded)) {
            throw new InvalidRestInputException('Ability input must be a JSON object, not a list.');
        }

        $object = [];
        foreach ($decoded as $key => $item) {
            $object[(string)$key] = $item;
        }

        return $object;
    }
}
