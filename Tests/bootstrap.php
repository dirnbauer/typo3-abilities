<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

// The MCP projection is compiled only when hn/typo3-mcp-server is installed;
// for unit tests the interface and SDK value objects are stubbed instead.
if (!interface_exists(\Hn\McpServer\MCP\Tool\ToolInterface::class)) {
    require __DIR__ . '/Stubs/McpStubs.php';
}
