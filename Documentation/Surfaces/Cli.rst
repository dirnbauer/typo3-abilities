..  include:: /Includes.rst.txt

..  _surfaces-cli:

===
CLI
===

..  _surfaces-cli-list:

Listing the registry
====================

..  code-block:: bash

    vendor/bin/typo3 abilities:list
    vendor/bin/typo3 abilities:list --category=content
    vendor/bin/typo3 abilities:list --json

The table shows each ability with its category, risk tier, scopes, side
effects and the surfaces it is exposed to. :bash:`--json` prints the full
definitions — the same structure the REST discovery endpoint returns.

..  _surfaces-cli-describe:

Describing one ability
======================

..  code-block:: bash

    vendor/bin/typo3 abilities:describe content/search

Prints the complete contract as JSON: metadata, annotations, the derived
REST method and MCP tool name, and both JSON Schemas. This is the command
to run before writing a client.

..  _surfaces-cli-run:

Running an ability
==================

..  code-block:: bash

    vendor/bin/typo3 abilities:run content/search --input '{"term": "roadmap", "limit": 5}'

The command prints the result envelope as JSON and exits non-zero when the
run failed or was denied, so it composes with shell scripts and CI.

..  confval:: --input
    :name: cli-run-input
    :type: string (JSON object)
    :Default: {}

    The ability input. Invalid JSON is rejected before anything runs.

..  confval:: --approve-review
    :name: cli-run-approve-review
    :type: flag

    Marks this execution as approved by a human, satisfying
    `review_required` policy rules. Deliberately a flag a person types:

    ..  code-block:: bash

        vendor/bin/typo3 abilities:run content/delete-page \
            --input '{"uid": 42}' --approve-review

..  confval:: --as-user
    :name: cli-run-as-user
    :type: string (backend username)

    Runs with that backend user's ability scopes instead of the trusted CLI
    context — the way to test what an editor is actually allowed to do:

    ..  code-block:: bash

        vendor/bin/typo3 abilities:run content/create-page-draft \
            --input '{"parent": 2, "title": "Press"}' --as-user=editor

..  note::
    The CLI is a *trusted* surface: without :bash:`--as-user` no scope
    checks run, because the shell already proves who you are. The site
    policy and each ability's :php:`checkPermission()` still apply, and
    every run is traced with surface `cli`.

..  _surfaces-cli-tokens:

Managing REST tokens
====================

..  code-block:: bash

    vendor/bin/typo3 abilities:token:create --user=editor --name="n8n production" \
        --scopes="content:read,pages:write" --expires=90
    vendor/bin/typo3 abilities:token:list
    vendor/bin/typo3 abilities:token:revoke 3

The plaintext token is printed exactly once — only its SHA-256 hash is
stored. The command also prints the *effective* scopes (token ∩ user), which
is what the token can really do.
