<?php

declare(strict_types=1);

namespace Webconsulting\Abilities\Http;

use Psr\Http\Message\ServerRequestInterface;

/**
 * Turns an HTTP request into an ability input object:
 *  - GET/DELETE: ?input=<json object>, or plain query parameters mapped
 *    onto the top-level properties of the input schema (with scalar
 *    coercion by declared type — everything arrives as a string)
 *  - POST/DELETE: JSON body {"input": {...}} (or a bare JSON object)
 *
 * Throws InvalidRestInputException for malformed input; the handler maps it
 * to 400 ability_invalid_input.
 */
final class RestInputMapper
{
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
            return $this->object($this->unwrap($request->getParsedBody()), 'body');
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
            if (!is_string($property) || !array_key_exists($property, $query)) {
                continue;
            }
            $input[$property] = $this->coerce($query[$property], is_array($propertySchema) ? $propertySchema : []);
        }

        return $input;
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

        return $this->object($this->unwrap($decoded), 'body');
    }

    /**
     * {"input": {...}} → {...}; a bare object is used as-is.
     *
     * @param array<mixed> $decoded
     * @return array<mixed>
     */
    private function unwrap(array $decoded): array
    {
        if (array_key_exists('input', $decoded)) {
            $input = $decoded['input'];
            if (!is_array($input)) {
                throw new InvalidRestInputException('"input" must be a JSON object.');
            }

            return $input;
        }

        return $decoded;
    }

    /**
     * @param array<mixed> $value
     * @return array<string, mixed>
     */
    private function object(array $value, string $where): array
    {
        if ($value !== [] && array_is_list($value)) {
            throw new InvalidRestInputException(sprintf('Ability input in the %s must be a JSON object, not a list.', $where));
        }
        $object = [];
        foreach ($value as $key => $item) {
            $object[(string)$key] = $item;
        }

        return $object;
    }

    /**
     * @param array<string, mixed> $propertySchema
     */
    private function coerce(mixed $value, array $propertySchema): mixed
    {
        if (!is_string($value)) {
            return $value;
        }
        $type = $propertySchema['type'] ?? null;
        $types = is_array($type) ? $type : [$type];

        foreach ($types as $candidate) {
            switch ($candidate) {
                case 'integer':
                    if (preg_match('/^-?\d+$/', $value) === 1) {
                        return (int)$value;
                    }
                    break;
                case 'number':
                    if (is_numeric($value)) {
                        return str_contains($value, '.') || str_contains(strtolower($value), 'e') ? (float)$value : (int)$value;
                    }
                    break;
                case 'boolean':
                    $bool = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                    if ($bool !== null) {
                        return $bool;
                    }
                    break;
                case 'null':
                    if ($value === '' || strtolower($value) === 'null') {
                        return null;
                    }
                    break;
                case 'array':
                case 'object':
                    $decoded = json_decode($value, true);
                    if (is_array($decoded)) {
                        return $decoded;
                    }
                    if ($candidate === 'array') {
                        return array_values(array_filter(array_map(trim(...), explode(',', $value)), static fn(string $item): bool => $item !== ''));
                    }
                    break;
            }
        }

        return $value;
    }
}
