<?php

declare(strict_types=1);

// PHPStan runs with the project autoloader; load the MCP stubs only when the
// real hn/typo3-mcp-server + logiscape/mcp-sdk-php classes are absent so the
// AbilityMcpTool hierarchy is natively reflectable either way.
if (!interface_exists(\Hn\McpServer\MCP\Tool\ToolInterface::class)) {
    require __DIR__ . '/Stubs/McpStubs.php';
}
