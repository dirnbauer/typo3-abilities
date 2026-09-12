..  include:: /Includes.rst.txt

..  _introduction:

============
Introduction
============

..  _introduction-what:

What this extension does
========================

The extension adds one **abilities registry** to a TYPO3 installation: a
single, typed, permissioned list of the things this site can do. An ability
is a PHP class that declares — in one attribute — its name, what it is for,
which scopes a caller needs, how risky it is, which subsystems it touches,
and whether it is read-only, destructive or idempotent. Its input and output
contracts are JSON Schemas on the class itself.

Everything else is a **projection** of that registry:

*   a CLI command runs an ability,
*   a REST endpoint lists, describes and runs abilities,
*   an MCP server turns each ability into a tool an AI agent can call,
*   the backend module lets an editor browse and run them,
*   a skill declares which abilities it needs.

Adding a new ability class makes it appear on all of those at once. There is
no endpoint to write, no tool list to maintain and no client to redeploy.

..  _introduction-why:

Why a registry instead of endpoints
===================================

Automation — agents, MCP clients, n8n, Zapier, a mobile app — needs to ask a
site *what it can do* and get a machine-readable answer. Hand-rolled
endpoints cannot answer that question: each one has to be discovered,
documented and integrated by a human.

Because every ability is a typed record in one registry, the site describes
itself:

..  code-block:: bash

    curl -H "Authorization: Bearer $TOKEN" https://example.org/abilities/v1/abilities

The answer carries each ability's JSON Schemas, its scopes, its risk tier
and its annotations. A generic client can build a correct call from that
alone — for an ability that did not exist when the client was written.

..  _introduction-wordpress:

Compared to the WordPress Abilities API
=======================================

WordPress shipped the **Abilities API** in core 6.9 together with an
official **MCP Adapter** plugin. This extension is the same architectural
bet for TYPO3, and it deliberately borrows the WordPress vocabulary —
categories, the `readonly` / `destructive` / `idempotent` / `instructions`
annotations, the `ability_invalid_input` / `ability_invalid_permissions` /
`ability_invalid_output` error codes and the method-by-annotation REST
layout — so that a team or an agent that knows one can read the other.

The differences are where TYPO3 is not WordPress: attributes and the DI
container instead of registration hooks, PSR-14 events instead of actions
and filters, backend groups and opaque tokens instead of capabilities, and
a governance layer (policy, human review, traces, risk tiers) that
WordPress does not have.

..  list-table:: WordPress Abilities API and TYPO3 Abilities Registry side by side
    :header-rows: 1
    :widths: 18 41 41

    *   -   Concern
        -   WordPress Abilities API
        -   This extension

    *   -   Registration
        -   :php:`wp_register_ability( 'my-plugin/create-post', [ 'label' =>
            …, 'description' => …, 'category' => …, 'input_schema' => …,
            'output_schema' => …, 'execute_callback' => …,
            'permission_callback' => …, 'meta' => … ] )`, called on the
            `wp_abilities_api_init` action.
        -   The :php:`#[AsAbility]` attribute on a class implementing
            :php:`AbilityInterface`. The DI container collects it — there is
            no registration call and no init hook to miss.

    *   -   Contracts
        -   `input_schema` and `output_schema` array keys, fixed at
            registration time.
        -   :php:`getInputSchema()` and :php:`getOutputSchema()` methods, so
            a schema may be computed at runtime (from TCA, from site
            configuration, from the caller's permissions).

    *   -   Categories
        -   :php:`wp_register_ability_category()` on the
            `wp_abilities_api_categories_init` action.
        -   :php:`#[AsAbilityCategory]` on any DI-managed class, or an
            :php:`AbilityCategoryProviderInterface` service. Nine categories
            ship built in; an unknown category is logged, never fatal.

    *   -   Annotations
        -   `readonly`, `destructive`, `idempotent` and free-form
            instructions in `meta`.
        -   The same four, as first-class attribute arguments.
            `readOnly` defaults to "declares no side effects", so the
            annotation cannot silently disagree with the side-effect list.

    *   -   Permissions
        -   One `permission_callback` per ability, evaluated against the
            WordPress capability of the current user.
        -   Three layers: the token/BE-group **scopes**
            (`resource:operation`) the caller was granted, TYPO3's own
            backend user and page permissions, and the ability's
            :php:`checkPermission()` for anything input-specific.

    *   -   Authentication
        -   Cookie, application passwords or whatever the REST request
            carries.
        -   Opaque bearer tokens bound to a backend user (SHA-256 hashed,
            scoped, expiring, revocable) or a same-origin backend session.
            A token can never exceed its user's own scopes.

    *   -   REST
        -   `/wp-json/wp-abilities/v1/abilities`, `…/{namespace}/{ability}`
            and `…/{namespace}/{ability}/run`; `show_in_rest` controls
            exposure.
        -   `/abilities/v1/abilities`, `…/abilities/{ns}/{name}` and
            `…/abilities/{ns}/{name}/run`, plus `…/categories`. The
            `expose` argument controls which surfaces may project an
            ability.

    *   -   Run method
        -   GET for read-only, DELETE for destructive, POST otherwise.
        -   Identical, derived from the same annotations
            (:php:`AbilityDefinition::restMethod()`).

    *   -   Extension points
        -   The `wp_before_execute_ability` and `wp_after_execute_ability`
            actions plus a set of filters.
        -   PSR-14 events: :php:`BeforeAbilityExecutionEvent` (rewrite the
            input or veto the run), :php:`AfterAbilityExecutionEvent` (every
            attempt, denials included) and
            :php:`ModifyAbilityDefinitionEvent` (change governance facts per
            installation).

    *   -   MCP
        -   A separate MCP Adapter plugin exposes abilities as MCP tools.
        -   :php:`McpProjection` produces protocol-neutral tool descriptors;
            an MCP server extension bridges them. This extension never
            depends on an MCP SDK.

    *   -   JavaScript
        -   The `@wordpress/abilities` package (`registerAbility`,
            `getAbilities`, `executeAbility`, …).
        -   The `@webconsulting/abilities/client.js` ES module with the same
            shape, talking to session-guarded backend AJAX routes.

    *   -   Policy
        -   —
        -   :file:`config/abilities-policy.yaml`: deny rules, a maximum risk
            tier and `review_required` rules, matched by name, namespace,
            risk tier, scope or side effect.

    *   -   Human in the loop
        -   —
        -   A `review_required` ability only runs with an explicit approval:
            :bash:`--approve-review` on the CLI, a checkbox in the backend
            module. REST can never approve and answers
            :code:`409 ability_review_required`.

    *   -   Auditing
        -   —
        -   Every attempt from every surface writes a
            :sql:`tx_abilities_trace` row: ability, surface, outcome, error
            code, duration, input and acting backend user.

    *   -   Risk and side effects
        -   —
        -   A four-step risk tier (low, medium, high, critical) and a
            subsystem side-effect vocabulary (`database:write`,
            `network:outbound`, `mail:send`, …) shared with TYPO3 capability
            manifests, so policies can reason about abilities without
            knowing them individually.

..  _introduction-screenshot:

The backend module
==================

*System > Abilities* is the registry seen from inside TYPO3: browse and
filter what is registered, run an ability as yourself through the governed
pipeline, read the execution traces and manage your REST tokens.

..  note::
    Screenshots of the four tabs are pending and will be added in a future
    revision of this manual.

..  _introduction-support:

Support and source
==================

*   Source code and issues:
    `github.com/dirnbauer/typo3-abilities <https://github.com/dirnbauer/typo3-abilities>`__
*   License: GPL-2.0-or-later
