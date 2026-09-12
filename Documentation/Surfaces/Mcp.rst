..  include:: /Includes.rst.txt

..  _surfaces-mcp:

===
MCP
===

..  _surfaces-mcp-projection:

A protocol-neutral projection
=============================

:php:`Projection\Mcp\McpProjection` turns every ability exposed to the `mcp`
surface into an :php:`McpToolDescriptor` — everything a `tools/list` entry
needs — **without referencing a single MCP SDK symbol**. An MCP server
extension (for example
`hn/typo3-mcp-server <https://github.com/dirnbauer/typo3-mcp-server>`__)
consumes the descriptors and owns the transport.

That separation is deliberate: the registry stays installable without an MCP
server, and a new protocol version is a change in the server extension, not
here.

..  _surfaces-mcp-tool-names:

Tool names and annotations
==========================

An ability name maps to a tool name by replacing the slash with an
underscore and prefixing `ability_`. Ability names contain no underscore, so
the mapping is collision-free and reversible:

..  code-block:: text

    content/search             →  ability_content_search
    content/delete-page        →  ability_content_delete-page
    workspace/publish          →  ability_workspace_publish

The tool description is the title, the description and — when present — the
:confval:`asability-instructions` text, which is where an agent is told when
*not* to call something. The MCP annotations come straight from the registry:

..  list-table::
    :header-rows: 1
    :widths: 30 70

    *   -   MCP annotation
        -   Source

    *   -   `title`
        -   :confval:`asability-title`

    *   -   `readOnlyHint`
        -   :confval:`asability-readOnly`, derived from the side effects
            when not set explicitly

    *   -   `destructiveHint`
        -   :confval:`asability-destructive`

    *   -   `idempotentHint`
        -   :confval:`asability-idempotent`

    *   -   `openWorldHint`
        -   Always `false` — abilities act on this installation

Honest annotations matter here more than anywhere else: a client decides
whether to ask the user for confirmation based on `destructiveHint`.

..  _surfaces-mcp-using:

Using the projection
====================

..  code-block:: php

    use Webconsulting\Abilities\Domain\ExecutionContext;
    use Webconsulting\Abilities\Projection\Mcp\McpProjection;

    public function __construct(private readonly McpProjection $projection) {}

    public function toolsList(): array
    {
        return array_map(
            static fn($descriptor): array => $descriptor->toArray(),
            [...$this->projection->descriptors()],
        );
    }

    public function toolsCall(string $toolName, array $arguments): array
    {
        return $this->projection
            ->execute($toolName, $arguments, ExecutionContext::mcp($backendUserUid))
            ->toArray();
    }

:php:`ExecutionContext::mcp()` is a trusted context: the MCP server has
already authenticated and gated the session, so scope checks are skipped.
The site policy and each ability's :php:`checkPermission()` still run, and
every call is traced with surface `mcp`.

..  warning::
    MCP sessions cannot approve a review. An ability behind a
    `review_required` rule fails with `ability_review_required` until a
    human approves it on the CLI or in the backend module — which is exactly
    what that rule is for.

..  _surfaces-mcp-hiding:

Keeping an ability away from agents
===================================

Drop `mcp` from :confval:`asability-expose`, or let a
:php:`ModifyAbilityDefinitionEvent` listener do it per installation — see
:ref:`events-modify-definition`.
