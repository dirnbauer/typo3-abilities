<?php

declare(strict_types=1);

/**
 * Minimal stand-ins for hn/typo3-mcp-server's ToolInterface and the
 * logiscape/mcp-sdk-php value objects it uses, matching the signatures the
 * AbilityMcpTool projection relies on. Tests/bootstrap.php requires this
 * file only when the real packages are absent; PHPStan scans it for the
 * same symbols.
 */

namespace Hn\McpServer\MCP\Tool {
    interface ToolInterface
    {
        public function getName(): string;

        /**
         * @return array<string, mixed>
         */
        public function getSchema(): array;

        /**
         * @param array<string, mixed> $params
         */
        public function execute(array $params): \Mcp\Types\CallToolResult;
    }
}

namespace Mcp\Types {
    class TextContent
    {
        public function __construct(
            public string $text,
        ) {
        }
    }

    class CallToolResult
    {
        /**
         * @param list<TextContent> $content
         */
        public function __construct(
            public array $content,
            public bool $isError = false,
        ) {
        }
    }
}
