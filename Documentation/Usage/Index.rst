..  include:: /Includes.rst.txt

..  _usage:

=====
Usage
=====

This chapter walks through every surface with the abilities that ship with
the extension. The transcripts come from a TYPO3 14.3 installation with the
shipped policy (:ref:`installation-policy`) in place.

..  _usage-demo:

The shipped abilities
=====================

..  list-table::
    :header-rows: 1
    :widths: 26 10 10 16 38

    *   -   Ability
        -   Risk
        -   REST
        -   Scope
        -   Demonstrates

    *   -   `abilities/catalog`
        -   low
        -   GET
        -   `abilities:read`
        -   Everything the installation can do, from every source.

    *   -   `abilities/list`
        -   low
        -   GET
        -   `abilities:read`
        -   Discovery of the registry through the registry.

    *   -   `abilities/describe`
        -   low
        -   GET
        -   `abilities:read`
        -   The full contract of one ability, on every surface.

    *   -   `system/site-info`
        -   low
        -   GET
        -   `system:read`
        -   The smallest possible read-only ability.

    *   -   `content/search`
        -   low
        -   GET
        -   `content:read`
        -   Typed query parameters over REST, workspace-aware queries,
            optional output properties.

    *   -   `content/create-page-draft`
        -   medium
        -   POST
        -   `pages:write`
        -   A governed write through the DataHandler; Before/After events.

    *   -   `content/delete-page`
        -   high
        -   DELETE
        -   `pages:write`
        -   Destructive annotation, DELETE method, the review gate.

    *   -   `workspace/publish`
        -   high
        -   POST
        -   `workspace:publish`
        -   Dry run by default, idempotency, human-in-the-loop publishing.

..  _usage-cli:

CLI
===

..  code-block:: bash

    $ vendor/bin/typo3 abilities:list
    +---------------------------+-----------------------+-----------+--------+-------------------+----------------+----------------+
    | Ability                   | Title                 | Category  | Risk   | Scopes            | Side effects   | Surfaces       |
    +---------------------------+-----------------------+-----------+--------+-------------------+----------------+----------------+
    | abilities/catalog         | Ability catalogue     | registry  | low    | abilities:read    | read-only      | mcp, cli, rest |
    | abilities/describe        | Describe ability      | registry  | low    | abilities:read    | read-only      | mcp, cli, rest |
    | abilities/list            | List abilities        | registry  | low    | abilities:read    | read-only      | mcp, cli, rest |
    | content/create-page-draft | Create page draft     | content   | medium | pages:write       | database:write | mcp, cli, rest |
    | content/delete-page       | Delete page           | content   | high   | pages:write       | database:write | mcp, cli, rest |
    | content/search            | Search content        | content   | low    | content:read      | read-only      | mcp, cli, rest |
    | system/site-info          | Site info             | system    | low    | system:read       | read-only      | mcp, cli, rest |
    | workspace/publish         | Publish workspace     | workspace | high   | workspace:publish | database:write | mcp, cli, rest |
    +---------------------------+-----------------------+-----------+--------+-------------------+----------------+----------------+

    $ vendor/bin/typo3 abilities:describe content/search
    {
        "name": "content/search",
        "title": "Search content",
        "annotations": {"readonly": true, "destructive": false, "idempotent": true, "instructions": "Use a short, distinctive term; …"},
        "mcpToolName": "ability_content_search",
        "restMethod": "GET",
        "inputSchema": {"type": "object", "required": ["term"], "properties": {"term": {"type": "string", "minLength": 2, …}, "limit": {…}, "language": {…}, "tables": {…}}},
        "outputSchema": {"type": "object", "required": ["results", "total"], …}
    }

    $ vendor/bin/typo3 abilities:run system/site-info
    {"ok": true, "data": {"typo3Version": "14.3.7", "sites": [{"identifier": "main", "rootPageId": 1, "base": "https://example.org/", "languages": ["en-US"]}]}}

    $ vendor/bin/typo3 abilities:run content/search --input '{"term": "roadmap", "limit": 5}'
    {"ok": true, "data": {"results": [{"table": "pages", "uid": 3, "pid": 1, "title": "Secret roadmap", "hidden": true, "language": 0}], "total": 1}}

    $ vendor/bin/typo3 abilities:run content/delete-page --input '{"uid": 42}'
    {"ok": false, "errorCode": "ability_review_required", "error": "Ability \"content/delete-page\" requires human review per rule \"risk:high\" of policy \"Default abilities policy\" and the execution context carries no approval."}

    $ vendor/bin/typo3 abilities:run content/delete-page --input '{"uid": 42}' --approve-review
    {"ok": true, "data": {"uid": 42, "title": "Old news", "deleted": true, "subpages": 0}}

    $ vendor/bin/typo3 abilities:run content/create-page-draft --input '{"parent": 2, "title": "Press"}' --as-user=editor
    {"ok": true, "data": {"uid": 57, "pid": 2, "slug": "/about/press", "hidden": true, "workspace": 0}}

The command exits non-zero on every denial or failure, so it composes with
shell scripts and CI. The CLI is trusted (no scope check) unless
:bash:`--as-user=<username>` runs with a real editor's scopes.

The catalogue on the CLI:

..  code-block:: bash

    $ vendor/bin/typo3 abilities:catalog --source=abilities --search=search
    +----------------+----------------+-----------+-----------------------------------------+-----------------------+-----------------------------------------------------------------------+
    | ID             | Title          | Source    | Surfaces                                | Annotations           | Invocation                                                            |
    +----------------+----------------+-----------+-----------------------------------------+-----------------------+-----------------------------------------------------------------------+
    | content/search | Search content | abilities | cli, mcp, rest, webhook, php, frontend  | readonly, idempotent  | vendor/bin/typo3 abilities:run content/search --input '{"term":"…"}' |
    |                |                |           |                                         |                       | ability_content_search                                                |
    |                |                |           |                                         |                       | GET /abilities/v1/abilities/content/search/run                        |
    |                |                |           |                                         |                       | EXT:reactions "Run ability" reaction → content/search                 |
    |                |                |           |                                         |                       | $executor->execute($registry->get('content/search'), …)              |
    |                |                |           |                                         |                       | dataProcessing.10 = …\AbilityProcessor; 10.ability = content/search   |
    +----------------+----------------+-----------+-----------------------------------------+-----------------------+-----------------------------------------------------------------------+
    1 catalogue entries (abilities: 1, cli: 0, mcp: 0, rest: 0, skills: 0). Use --format=json for the machine-readable catalogue …

    $ vendor/bin/typo3 abilities:catalog --format=json > catalog.json        # hand this to an LLM as tool context
    $ vendor/bin/typo3 abilities:list --source=cli                           # one source at a time
    $ vendor/bin/typo3 abilities:catalog --surface=mcp                       # only what an MCP client can call

Sources: `abilities` (this registry), `mcp` (native tools of
hn/typo3-mcp-server), `skills` (nr-llm / skillflow skills), `rest` (the REST
projection, EXT:reactions webhooks, sg-apicore endpoints), `cli` (every
console command with a schema derived from its arguments and options).

Tokens: :bash:`abilities:token:create|list|revoke`, see
:ref:`configuration-tokens`.

..  _usage-rest:

REST
====

..  code-block:: bash

    TOKEN=$(vendor/bin/typo3 abilities:token:create --user=editor --name=demo --scopes='*' --json | jq -r .token)
    H="Authorization: Bearer $TOKEN"

    # Discover
    curl -s -H "$H" https://example.org/abilities/v1/abilities | jq '.data.abilities[].name'
    curl -s -H "$H" https://example.org/abilities/v1/abilities/content/search | jq '.data.inputSchema'
    curl -s -H "$H" 'https://example.org/abilities/v1/catalog?source=cli&search=cache' | jq '.data.entries[].invocations'

    # Read-only → GET, input as typed query parameters (or ?input=<json>)
    curl -s -H "$H" 'https://example.org/abilities/v1/abilities/content/search/run?term=roadmap&limit=5&tables=pages'
    # {"ok":true,"data":{"results":[…],"total":1}}

    # Write → POST with a JSON body
    curl -s -X POST -H "$H" -H 'Content-Type: application/json' \
      -d '{"input": {"parent": 2, "title": "Press release"}}' \
      https://example.org/abilities/v1/abilities/content/create-page-draft/run
    # {"ok":true,"data":{"uid":57,"pid":2,"slug":"/about/press-release","hidden":true,"workspace":0}}

    # Destructive → DELETE; review-gated → 409, nothing runs
    curl -s -X DELETE -H "$H" -d '{"input": {"uid": 42}}' https://example.org/abilities/v1/abilities/content/delete-page/run
    # {"ok":false,"code":"ability_review_required","message":"…"}

    # The wrong method is a contract violation, not a convention
    curl -s -X POST -H "$H" https://example.org/abilities/v1/abilities/content/search/run
    # 405, Allow: GET, {"ok":false,"code":"rest_ability_invalid_method",…}

..  list-table:: Endpoints below the base path (default `/abilities/v1`)
    :header-rows: 1
    :widths: 20 45 35

    *   -   Method
        -   Path
        -   Purpose

    *   -   GET
        -   :code:`/abilities`
        -   List REST-exposed abilities; `category`, `page`, `per_page`
            (max 100); `X-Total`, `X-Total-Pages` headers.

    *   -   GET
        -   :code:`/abilities/{namespace}/{name}`
        -   Full contract with both schemas.

    *   -   GET / POST / DELETE
        -   :code:`/abilities/{namespace}/{name}/run`
        -   Run; the method is fixed by the annotations.

    *   -   GET
        -   :code:`/categories`, :code:`/categories/{slug}`
        -   Categories, each flagged whether an ability uses it.

    *   -   GET
        -   :code:`/catalog`
        -   The ability catalogue; `source`, `surface`, `search`.

Responses are :code:`{"ok": true, "data": …}` or
:code:`{"ok": false, "code": "…", "message": "…"}`, always
:code:`Cache-Control: no-store`. Statuses: 400 invalid input, 401
unauthenticated, 403 permissions or policy, 404 unknown, 405 wrong method,
409 review required, 500 the ability failed. :code:`X-TYPO3-Workspace: 3`
runs the request inside a workspace.

..  _usage-mcp:

MCP
===

With `hn/typo3-mcp-server` installed every MCP-exposed ability is a tool
named after the ability (the slash becomes an underscore; ability names
contain no underscore, so the mapping is reversible):

..  code-block:: text

    ability_abilities_catalog          ability_content_create-page-draft
    ability_abilities_list             ability_content_delete-page
    ability_abilities_describe         ability_content_search
    ability_system_site-info           ability_workspace_publish

The tool description is title, description and instructions; the MCP
annotations come from the registry:

..  code-block:: json

    {
      "name": "ability_content_delete-page",
      "description": "Delete page — Soft-deletes a page … Destructive. Confirm the uid with content/search first …",
      "inputSchema": {"type": "object", "required": ["uid"], "properties": {"uid": {"type": "integer", "minimum": 1}, "recursive": {"type": "boolean", "default": false}}},
      "annotations": {"title": "Delete page", "readOnlyHint": false, "destructiveHint": true, "idempotentHint": false, "openWorldHint": false}
    }

An agent's first call is `ability_abilities_catalog`; the answer lists the
native MCP tools too, so it does not need a second discovery step. MCP is a
trusted surface (the server authenticated the session); the policy and each
ability's permission check still apply, review-gated abilities fail with
`ability_review_required` until a human approves them on the CLI or in the
module, and every call is traced with surface `mcp`.

..  _usage-webhook:

Webhooks
========

With `typo3/cms-reactions`: create a reaction of type
:guilabel:`Run ability (abilities registry)`, pick `content/search` and the
backend user `editor`, note the identifier and secret, then:

..  code-block:: bash

    curl -s -X POST -H 'x-api-key: <secret>' -H 'Content-Type: application/json' \
      -d '{"input": {"term": "roadmap"}}' https://example.org/typo3/reaction/<identifier>
    # {"ok":true,"data":{"results":[…],"total":1}}

The run acts as `editor` with that user's scopes and is traced with surface
`webhook`. Bind a `risk:high` ability and the webhook answers 409 — a
webhook cannot approve a review either.

..  _usage-fluid:

Fluid
=====

..  code-block:: typoscript

    page.10.dataProcessing.20 = Webconsulting\Abilities\DataProcessing\AbilityProcessor
    page.10.dataProcessing.20 {
      ability = content/search
      input.term.field = title
      input.limit = 5
      as = related
    }

..  code-block:: html

    <f:if condition="{related.ok}">
      <f:for each="{related.data.results}" as="hit"><a href="{hit.url}">{hit.title}</a></f:for>
    </f:if>

Only read-only abilities may render (:ref:`configuration-fluid`).

..  _usage-backend-module:

Backend module
==============

:guilabel:`System > Abilities` needs no token: the logged-in backend user is
the identity and runs execute with that user's group scopes (admins: `*`).

*   **Registry** — the whole registry with filters (text, category, surface,
    risk) and a :guilabel:`Run` button per row.
*   **Catalogue** — every ability from every source, filterable by text,
    source and surface; each row folds its invocations per surface.
*   **Run** — a form generated from the ability's input schema (enum →
    select, boolean → checkbox, number → number input, objects → JSON), the
    review checkbox when the policy asks for one, a confirmation for
    destructive abilities, and the result with its trace uid.
*   **Traces** — the newest :sql:`tx_abilities_trace` rows, filterable by
    ability, surface and outcome.
*   **Tokens** — create (plaintext shown once, with a copy button), list,
    revoke.

The module is translated into English and German; its JavaScript reads its
labels from the `~labels/abilities.mod` module, so no string is hard-coded.

..  _usage-js:

JavaScript client
=================

The ES module `@webconsulting/abilities/client.js` works in any backend
module (session-guarded AJAX routes, no token):

..  code-block:: js

    import { getAbilities, getAbility, executeAbility, getCatalog } from "@webconsulting/abilities/client.js";

    const { abilities } = await getAbilities({ category: "content" });
    const contract = await getAbility("content/search");     // inputSchema, outputSchema, policy.reviewRequired
    const result = await executeAbility("content/search", { term: "roadmap", limit: 5 });
    if (result.ok) console.log(result.data.results); else console.warn(result.errorCode, result.error);

    await executeAbility("content/delete-page", { uid: 42 }, { approveReview: true });

    const { entries } = await getCatalog({ surface: "mcp", search: "page" });

`registerAbility()` adds browser-only abilities (same shape as
:php:`#[AsAbility]`; they are not governed by the server pipeline);
`getTokens()`, `createToken()`, `revokeToken()` and `getTraces()` mirror the
module's tabs.

..  _usage-skills:

Skills
======

A skill declares the abilities it needs in its front matter;
:php:`Skills\SkillAbilityContract` resolves them to the tool names an agent
client sees and validates them against the registry and the policy:

..  code-block:: yaml

    ---
    name: publish-editorial-drafts
    description: Reviews the pending drafts with a human and publishes them once approved.
    abilities:
      - workspace/publish
      - content/search
    ---

..  code-block:: php

    $contract->resolveMcpToolNames(['workspace/publish', 'content/search']);
    // ['workspace/publish' => 'mcp__typo3__ability_workspace_publish', 'content/search' => 'mcp__typo3__ability_content_search']
    $contract->validate(['workspace/publish', 'news/nope']);
    // [['ability' => 'workspace/publish', 'code' => 'review_required', …], ['ability' => 'news/nope', 'code' => 'missing', …]]

Skills stored by nr-llm or skillflow appear in the catalogue as
`skill/<name>` with their declared abilities.

..  _usage-scheduler:

Scheduler
=========

:guilabel:`Scheduler > Execute console command > abilities:run` with the
input and, for review-gated abilities, :bash:`--approve-review`. The
catalogue lists every schedulable command with surface `scheduler`.

..  _usage-php:

PHP
===

..  code-block:: php

    $result = $this->executor->execute(
        $this->registry->get('content/search'),
        ['term' => 'roadmap'],
        new ExecutionContext(ExecutionContext::SURFACE_PHP, ['content:read']),
        $this->registry->getDefinition('content/search'),
    );
    // $result->ok, $result->data, $result->errorCode, $result->error
