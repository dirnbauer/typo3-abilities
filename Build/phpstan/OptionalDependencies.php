<?php

declare(strict_types=1);

/*
 * Symbol declarations of OPTIONAL dependencies for PHPStan only (phpstan.neon
 * scanFiles). The catalogue sources reference these classes behind
 * class_exists() guards and nullable constructor parameters; the packages
 * themselves are not part of this extension's dependency graph:
 *
 *   hn/typo3-mcp-server    — McpToolSource reads its ToolRegistry
 *   sgalinski/sg-apicore   — RestEndpointSource reads its endpoint discovery
 *
 * The signatures mirror the real classes. This file is never autoloaded.
 */

namespace Hn\McpServer\MCP\Tool {
    abstract class AbstractTool
    {
        abstract public function getName(): string;

        /**
         * @return array<string, mixed>
         */
        abstract public function getSchema(): array;
    }
}

namespace Hn\McpServer\MCP {
    final class ToolRegistry
    {
        /**
         * @return array<string, Tool\AbstractTool>
         */
        public function getTools(): array
        {
            return [];
        }
    }
}

namespace SGalinski\SgApicore\Service {
    class EndpointDiscoveryService
    {
        /**
         * @return array<int, array<string, mixed>>
         */
        public function getAllEndpoints(): array
        {
            return [];
        }
    }
}

namespace SGalinski\SgApicore\Configuration {
    class ExtensionConfiguration
    {
        public function getApiPathPrefix(): string
        {
            return '/api/';
        }
    }
}
