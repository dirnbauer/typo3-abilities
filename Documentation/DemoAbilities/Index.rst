..  include:: /Includes.rst.txt

..  _demo-abilities:

==============
Demo abilities
==============

The extension ships seven abilities. Three are infrastructure — they make
the registry describe itself — and four are working demonstrations of the
governance features, written to be read: each one shows a different part of
the pipeline, and together they cover read, write, destroy and
human-in-the-loop.

They are real, not toys. Use them, or copy them as the starting point for
your own.

..  list-table::
    :header-rows: 1
    :widths: 26 12 10 14 38

    *   -   Ability
        -   Risk
        -   REST
        -   Scope
        -   Demonstrates

    *   -   `system/site-info`
        -   low
        -   GET
        -   `system:read`
        -   The smallest possible read-only ability.

    *   -   `abilities/list`
        -   low
        -   GET
        -   `abilities:read`
        -   Discovery as an ability, so agents find the registry through the
            registry.

    *   -   `abilities/describe`
        -   low
        -   GET
        -   `abilities:read`
        -   The full contract of one ability, on every surface.

    *   -   `content/search`
        -   low
        -   GET
        -   `content:read`
        -   Typed query parameters over REST, workspace-aware queries.

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
        -   Dry run, idempotency, human-in-the-loop publishing.

..  _demo-abilities-search:

content/search
==============

Searches pages (title, subtitle, navigation title) and content elements
(header, bodytext) for a term.

..  code-block:: bash

    vendor/bin/typo3 abilities:run content/search --input '{"term": "roadmap", "limit": 5}'

..  code-block:: bash

    curl -H "Authorization: Bearer $TOKEN" \
      "https://example.org/abilities/v1/abilities/content/search/run?term=roadmap&limit=5&tables=pages"

Input: `term` (required, ≥ 2 characters), `limit` (default 20, max 100),
`language` (a `sys_language_uid`, or null for all) and `tables`
(`pages`, `tt_content` or both). Output: a list of
`{table, uid, pid, title, hidden, language, url?}` plus `total`.

Worth reading in the source:

*   The query runs through a **WorkspaceRestriction** with the acting user's
    workspace, so an editor working in a draft workspace finds their own
    drafts — and delete placeholders are filtered out.
*   Hidden records are found and flagged, but get **no** `url`. TYPO3's page
    router cannot route a page it considers invisible and would fall back to
    the site base; a URL that silently points at the wrong page is worse for
    an agent than no URL at all.
*   `url` is an optional output property — the honest way to model "there
    may or may not be one".

..  _demo-abilities-create-page-draft:

content/create-page-draft
=========================

Creates a hidden page below a parent through the DataHandler, as the acting
backend user.

..  code-block:: bash

    curl -X POST -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json" \
      -d '{"input": {"parent": 2, "title": "Press release"}}' \
      https://example.org/abilities/v1/abilities/content/create-page-draft/run

Input: `parent` (uid, 0 = root level and admins only), `title`, optional
`slug` (generated when empty) and `doktype` (default 1). Output:
`{uid, pid, slug, hidden, workspace}`.

Worth reading in the source:

*   The page is **always** created hidden. An ability that publishes as a
    side effect of creating is an ability nobody can safely give to an
    agent.
*   Going through the DataHandler means page permissions, slug generation,
    the reference index, the history log and — for a user inside a
    workspace — a workspace version rather than a live record. The returned
    `workspace` says which happened.
*   :php:`checkPermission()` checks :php:`Permission::PAGE_NEW` on the
    parent for non-admins and returns the reason as a string.

This is also the ability the event example uses: a
:php:`BeforeAbilityExecutionEvent` listener can prefix every agent-created
title or veto the run outright — see :ref:`events-before`.

..  _demo-abilities-delete-page:

content/delete-page
===================

Soft-deletes a page and its content through the DataHandler.

..  code-block:: bash

    vendor/bin/typo3 abilities:run content/delete-page \
        --input '{"uid": 42}' --approve-review

Input: `uid` and `recursive` (default `false`). Output:
`{uid, title, deleted, subpages}`.

Worth reading in the source:

*   It is annotated :confval:`asability-destructive`, so REST serves it with
    **DELETE** and MCP clients see `destructiveHint: true`.
*   It is `risk: high`, and the shipped policy example puts `risk:high`
    behind a review. Over REST it therefore answers
    :code:`409 ability_review_required` — no bearer token can delete a page
    unattended. The CLI flag above and the module checkbox are the approval.
*   A page with subpages is **refused** unless `recursive` is set: a whole
    branch disappearing because of an ambiguous request is exactly the
    accident this guard prevents.
*   `idempotent` is `false` and the deletion is a soft delete — the page is
    restorable from the recycler.

..  _demo-abilities-workspace-publish:

workspace/publish
=================

Lists or publishes everything pending in a workspace — the same operation as
the :guilabel:`Publish` button of the Workspaces module.

..  code-block:: bash

    # 1. What would go live?
    vendor/bin/typo3 abilities:run workspace/publish --input '{"workspace": 1}' --approve-review

    # 2. Do it.
    vendor/bin/typo3 abilities:run workspace/publish \
        --input '{"workspace": 1, "dryRun": false}' --approve-review

Input: `workspace` (uid) and `dryRun` (**default `true`**). Output:
`{workspace, title, dryRun, pending, published, records[]}`, where each
record carries table, version uid, live uid, pid, title and stage.

Worth reading in the source:

*   `dryRun` defaults to `true`. The safe operation is the default one, so
    a client that forgets the parameter *lists* instead of publishing.
*   It is :confval:`asability-idempotent`: publishing an already published
    workspace finds nothing pending and is a no-op, so a retry after a
    timeout cannot do damage.
*   Publish access mirrors the Core rules (admins and workspace owners
    always; members only when the workspace does not restrict publishing to
    owners), and a workspace configured for "publish only from the publish
    stage" is honoured.
*   EXT:workspaces stays **optional**: the ability is registered either way
    and denies with a clear message when the extension is absent, instead of
    disappearing from the registry and leaving an agent to guess.

..  _demo-abilities-policy:

Trying the governance out
=========================

..  code-block:: bash

    # Install the shipped policy: risk:high needs a human.
    cp vendor/webconsulting/typo3-abilities/Resources/Private/Examples/abilities-policy.yaml \
       config/abilities-policy.yaml

    # Denied — no approval.
    vendor/bin/typo3 abilities:run content/delete-page --input '{"uid": 42}'
    # {"ok": false, "errorCode": "ability_review_required", …}

    # Approved by a person.
    vendor/bin/typo3 abilities:run content/delete-page --input '{"uid": 42}' --approve-review

Every one of those attempts — including the denial — is now a row in the
:guilabel:`Traces` tab of the backend module.
