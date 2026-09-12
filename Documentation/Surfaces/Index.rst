..  include:: /Includes.rst.txt

..  _surfaces:

========
Surfaces
========

A surface is a projection of the registry onto one protocol. A projection
never re-implements an ability: it looks it up, builds an
:php:`ExecutionContext` and calls the same executor everything else calls.

..  list-table::
    :header-rows: 1
    :widths: 20 20 60

    *   -   Surface
        -   Trust
        -   Identity

    *   -   :ref:`CLI <surfaces-cli>`
        -   trusted
        -   The `_cli_` backend user, or `--as-user=<username>` to run with
            a real user's scopes. May approve a review.

    *   -   :ref:`REST <surfaces-rest>`
        -   scoped
        -   A bearer token bound to a backend user (token scopes ∩ user
            scopes), or a same-origin backend session. Never approves a
            review.

    *   -   :ref:`MCP <surfaces-mcp>`
        -   trusted
        -   Whatever the MCP server authenticated. Only abilities exposed to
            `mcp` are projected.

    *   -   :ref:`Backend module <surfaces-backend-module>`
        -   scoped
        -   The logged-in backend user, with the scopes their groups grant
            (admins: `*`). May approve a review.

    *   -   :ref:`JavaScript client <surfaces-js-client>`
        -   scoped
        -   The backend session of the browser it runs in.

    *   -   :ref:`Skills <surfaces-skills>`
        -   —
        -   Not an execution surface: a contract that resolves and validates
            the abilities a skill declares.

..  toctree::
    :maxdepth: 1
    :titlesonly:

    Cli
    Rest
    Mcp
    BackendModule
    JsClient
    Skills
