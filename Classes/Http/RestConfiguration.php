<?php

declare(strict_types=1);

namespace Webconsulting\Abilities\Http;

use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;

/**
 * Extension settings of the REST projection (ext_conf_template.txt), with
 * safe defaults when the configuration has not been written yet.
 */
final readonly class RestConfiguration
{
    public const string DEFAULT_BASE_PATH = '/abilities/v1';

    public function __construct(
        public bool $enabled = true,
        public string $basePath = self::DEFAULT_BASE_PATH,
        public string $corsOrigins = '',
    ) {}

    public static function fromExtensionConfiguration(ExtensionConfiguration $extensionConfiguration): self
    {
        try {
            $values = $extensionConfiguration->get('abilities');
        } catch (\Throwable) {
            $values = [];
        }

        return self::fromArray(is_array($values) ? $values : []);
    }

    /**
     * @param array<mixed> $values
     */
    public static function fromArray(array $values): self
    {
        $enabled = $values['restEnabled'] ?? true;
        $basePath = $values['restBasePath'] ?? self::DEFAULT_BASE_PATH;
        $corsOrigins = $values['restCorsOrigins'] ?? '';

        return new self(
            enabled: is_bool($enabled) ? $enabled : filter_var($enabled, FILTER_VALIDATE_BOOLEAN),
            basePath: self::normalizeBasePath(is_string($basePath) ? $basePath : self::DEFAULT_BASE_PATH),
            corsOrigins: is_string($corsOrigins) ? trim($corsOrigins) : '',
        );
    }

    private static function normalizeBasePath(string $basePath): string
    {
        $basePath = '/' . trim($basePath, '/');

        return $basePath === '/' ? self::DEFAULT_BASE_PATH : $basePath;
    }
}
