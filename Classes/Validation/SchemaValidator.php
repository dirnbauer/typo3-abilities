<?php

declare(strict_types=1);

namespace Webconsulting\Abilities\Validation;

/**
 * Dependency-free validator for the JSON Schema subset ability contracts
 * use. Deliberately small so the extension stays free of third-party
 * dependencies (a prerequisite for ever proposing this upstream).
 *
 * Supported keywords: type (incl. union arrays), properties, required,
 * additionalProperties (boolean), items, enum, minimum, maximum, minLength,
 * maxLength, pattern, minItems, maxItems, default (top-level object
 * properties, via applyDefaults()).
 *
 * An empty schema ([]) accepts any value.
 */
final class SchemaValidator
{
    /**
     * Fill in declared defaults for missing top-level object properties.
     *
     * @param array<string, mixed> $input
     * @param array<mixed> $schema
     * @return array<string, mixed>
     */
    public function applyDefaults(array $input, array $schema): array
    {
        $properties = $schema['properties'] ?? null;
        if (!is_array($properties)) {
            return $input;
        }

        foreach ($properties as $property => $propertySchema) {
            if (is_string($property)
                && !array_key_exists($property, $input)
                && is_array($propertySchema)
                && array_key_exists('default', $propertySchema)
            ) {
                $input[$property] = $propertySchema['default'];
            }
        }

        return $input;
    }

    /**
     * @param array<mixed> $schema
     * @return list<string> validation error messages; empty = valid
     */
    public function validate(mixed $value, array $schema, string $path = '$'): array
    {
        if ($schema === []) {
            return [];
        }

        $errors = [];

        if (isset($schema['type'])) {
            $types = is_array($schema['type']) ? array_values($schema['type']) : [$schema['type']];
            if (!$this->matchesAnyType($value, $types)) {
                return [sprintf(
                    '%s: expected type %s, got %s',
                    $path,
                    implode('|', array_map(
                        static fn(mixed $type): string => is_string($type) ? $type : get_debug_type($type),
                        $types,
                    )),
                    get_debug_type($value),
                )];
            }
        }

        if (array_key_exists('enum', $schema) && is_array($schema['enum'])
            && !in_array($value, $schema['enum'], true)
        ) {
            $errors[] = sprintf(
                '%s: value is not one of the allowed enum values (%s)',
                $path,
                implode(', ', array_map(
                    static fn(mixed $option): string => json_encode($option, JSON_UNESCAPED_SLASHES) ?: '?',
                    $schema['enum'],
                )),
            );
        }

        if (is_string($value)) {
            $errors = [...$errors, ...$this->validateString($value, $schema, $path)];
        }

        if (is_int($value) || is_float($value)) {
            $errors = [...$errors, ...$this->validateNumber($value, $schema, $path)];
        }

        if (is_array($value)) {
            // A PHP [] is both JSON [] and {} — run it through both branches
            // so minItems and required each get their chance to complain.
            if (array_is_list($value)) {
                $errors = [...$errors, ...$this->validateList($value, $schema, $path)];
            }
            if ($value === [] || !array_is_list($value)) {
                $errors = [...$errors, ...$this->validateObject($value, $schema, $path)];
            }
        }

        return $errors;
    }

    /**
     * @param list<string|mixed> $types
     */
    private function matchesAnyType(mixed $value, array $types): bool
    {
        foreach ($types as $type) {
            $matches = match ($type) {
                'string' => is_string($value),
                'integer' => is_int($value),
                'number' => is_int($value) || is_float($value),
                'boolean' => is_bool($value),
                'null' => $value === null,
                'array' => is_array($value) && array_is_list($value),
                'object' => is_array($value) && ($value === [] || !array_is_list($value)),
                default => false,
            };
            if ($matches) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<mixed> $schema
     * @return list<string>
     */
    private function validateString(string $value, array $schema, string $path): array
    {
        $errors = [];
        $length = mb_strlen($value);

        if (isset($schema['minLength']) && is_int($schema['minLength']) && $length < $schema['minLength']) {
            $errors[] = sprintf('%s: string is shorter than minLength %d', $path, $schema['minLength']);
        }
        if (isset($schema['maxLength']) && is_int($schema['maxLength']) && $length > $schema['maxLength']) {
            $errors[] = sprintf('%s: string is longer than maxLength %d', $path, $schema['maxLength']);
        }
        if (isset($schema['pattern']) && is_string($schema['pattern'])
            && preg_match('{' . str_replace('{', '\{', $schema['pattern']) . '}u', $value) !== 1
        ) {
            $errors[] = sprintf('%s: string does not match pattern %s', $path, $schema['pattern']);
        }

        return $errors;
    }

    /**
     * @param array<mixed> $schema
     * @return list<string>
     */
    private function validateNumber(int|float $value, array $schema, string $path): array
    {
        $errors = [];

        if (isset($schema['minimum']) && (is_int($schema['minimum']) || is_float($schema['minimum']))
            && $value < $schema['minimum']
        ) {
            $errors[] = sprintf('%s: value is smaller than minimum %s', $path, (string)$schema['minimum']);
        }
        if (isset($schema['maximum']) && (is_int($schema['maximum']) || is_float($schema['maximum']))
            && $value > $schema['maximum']
        ) {
            $errors[] = sprintf('%s: value is larger than maximum %s', $path, (string)$schema['maximum']);
        }

        return $errors;
    }

    /**
     * @param list<mixed> $value
     * @param array<mixed> $schema
     * @return list<string>
     */
    private function validateList(array $value, array $schema, string $path): array
    {
        $errors = [];
        $count = count($value);

        if (isset($schema['minItems']) && is_int($schema['minItems']) && $count < $schema['minItems']) {
            $errors[] = sprintf('%s: array has fewer than minItems %d items', $path, $schema['minItems']);
        }
        if (isset($schema['maxItems']) && is_int($schema['maxItems']) && $count > $schema['maxItems']) {
            $errors[] = sprintf('%s: array has more than maxItems %d items', $path, $schema['maxItems']);
        }

        if (isset($schema['items']) && is_array($schema['items'])) {
            foreach ($value as $index => $item) {
                $errors = [...$errors, ...$this->validate($item, $schema['items'], $path . '[' . $index . ']')];
            }
        }

        return $errors;
    }

    /**
     * @param array<mixed> $value
     * @param array<mixed> $schema
     * @return list<string>
     */
    private function validateObject(array $value, array $schema, string $path): array
    {
        $errors = [];
        $properties = is_array($schema['properties'] ?? null) ? $schema['properties'] : [];

        if (isset($schema['required']) && is_array($schema['required'])) {
            foreach ($schema['required'] as $required) {
                if (is_string($required) && !array_key_exists($required, $value)) {
                    $errors[] = sprintf('%s: missing required property "%s"', $path, $required);
                }
            }
        }

        foreach ($value as $property => $propertyValue) {
            $propertySchema = $properties[$property] ?? null;
            if (is_array($propertySchema)) {
                $errors = [...$errors, ...$this->validate($propertyValue, $propertySchema, $path . '.' . $property)];
            } elseif (($schema['additionalProperties'] ?? true) === false) {
                $errors[] = sprintf('%s: unexpected additional property "%s"', $path, (string)$property);
            }
        }

        return $errors;
    }
}
