..  include:: /Includes.rst.txt

..  _introduction:

============
Introduction
============

..  _introduction-what:

What this extension does
========================

The extension adds two things to a TYPO3 installation.

An **abilities registry**: a single, typed, permissioned list of the things
this site can do. An ability is a PHP class that declares — in one attribute —
its name, what it is for, which scopes a caller needs, how risky it is, which
subsystems it touches and whether it is read-only, destructive or idempotent.
Its input and output contracts are JSON Schemas. Everything else is a
**projection** of that registry: a CLI command, a REST endpoint, an MCP tool,
a webhook reaction, a Fluid data processor, the backend module and the
skills contract all look an ability up and call the same executor. Adding a
class makes it appear everywhere at once.

An **ability catalogue**: the union of that registry with everything else
an AI agent could use on this installation — the native tools of the MCP
server, the agent skills stored by nr-llm or skillflow, the REST and webhook
endpoints and the console commands. Every entry has the same shape (id,
title, description, source, surfaces, input schema, annotations and how to
invoke it from each surface), and the catalogue itself is served on every
surface: :bash:`abilities:catalog`, :code:`GET /abilities/v1/catalog`, the
MCP tool :code:`ability_abilities_catalog` and the module's Catalogue tab.
An agent discovers first, then uses.

..  _introduction-why:

Why a registry and a catalogue
==============================

Automation — agents, MCP clients, n8n, a mobile app — needs to ask a site
*what it can do* and get a machine-readable answer. Hand-rolled endpoints
cannot answer that: each one has to be discovered, documented and integrated
by a human. A typed registry describes itself, and the catalogue extends the
answer to the parts of the installation the registry did not create:

..  code-block:: bash

    vendor/bin/typo3 abilities:catalog --format=json > catalog.json

The file carries every entry's schema, annotations and invocations. Handed to
an LLM as tool context, it lets a generic agent build a correct call for an
ability that did not exist when the agent was written.

..  _introduction-wordpress:

WordPress Abilities API parity
==============================

WordPress shipped the **Abilities API** in core 6.9 with an official MCP
adapter. This extension is the same architectural bet for TYPO3 and follows
the WordPress best practices literally where TYPO3 has an equivalent — the
checklist below says what matches, what differs and why. Sources: the
`Abilities API handbook <https://developer.wordpress.org/apis/abilities-api/>`__
and its PHP, REST, hooks and JavaScript references.

..  list-table:: Parity checklist
    :header-rows: 1
    :widths: 18 30 30 22

    *   -   Concern
        -   WordPress Abilities API
        -   This extension
        -   Verdict

    *   -   Vocabulary
        -   An **ability** is a unit of functionality; a **capability** is a
            user permission, the thing :php:`current_user_can()` checks.
        -   Identical. An ability is a unit of functionality — registered
            here or discovered from an MCP tool, a skill, a command or an
            endpoint — and "capability" is reserved for the MCP capability
            manifest's permission gating, never used for discovery.
        -   Matches.

    *   -   Registration
        -   :php:`wp_register_ability( 'ns/name', $args )` on the
            `wp_abilities_api_init` action.
        -   :php:`#[AsAbility]` on a class implementing :php:`AbilityInterface`;
            the DI container collects it at compile time.
        -   Matches (attribute instead of a hook call).

    *   -   Naming
        -   `namespace/ability-name`, lowercase, hyphens, one slash;
            namespace = plugin slug.
        -   Identical pattern, validated when the container is built; namespace
            names the thing acted on (`content`, `workspace`).
        -   Matches.

    *   -   Label and description
        -   `label` + `description`, "crucial for AI agents to understand
            how and when to use the ability".
        -   `title` + `description` + `instructions` (when to call, what to
            do first); the catalogue writes every entry the same way.
        -   Matches, plus `instructions`.

    *   -   Category
        -   Required; must be registered first with
            :php:`wp_register_ability_category()` on
            `wp_abilities_api_categories_init`.
        -   :php:`#[AsAbilityCategory]` or a provider service; nine
            categories ship built in; an unknown slug is logged, not fatal.
        -   Differs: lenient by design — a missing category must not take an
            ability offline.

    *   -   Contracts
        -   `input_schema` optional, `output_schema` required; JSON Schema;
            input is validated before the permission callback runs.
        -   :php:`getInputSchema()` / :php:`getOutputSchema()`, computed at
            runtime; :php:`[]` skips validation; input is validated before
            the permission check.
        -   Matches (output schema optional here).

    *   -   Permission
        -   One `permission_callback`, boolean or `WP_Error`.
        -   Three layers: granted **scopes**, the site **policy**, the
            ability's :php:`checkPermission()` returning `true`, `false` or a
            reason string.
        -   Matches, plus scopes and policy.

    *   -   Execution order
        -   validate input → permission → execute → validate output.
        -   Before event → policy → validate input → scopes → permission →
            execute → validate output → After event.
        -   Matches; governance runs first.

    *   -   Annotations
        -   `meta.annotations`: `readonly` (default false), `destructive`
            (default **true**), `idempotent` (default false), `instructions`.
        -   The same four as attribute arguments. `readOnly` defaults to
            "declares no side effects"; `destructive` defaults to false.
        -   Differs on the `destructive` default: here `sideEffects` and the
            `riskTier` already say what an ability touches, so `destructive`
            keeps its narrow meaning (irreversible deletion).

    *   -   REST exposure
        -   `meta.show_in_rest`, default false.
        -   `expose: ['mcp', 'cli', 'rest']`, default all three; drop a
            surface to hide an ability from it.
        -   Differs: opt-out instead of opt-in, because the registry exists
            for agents; tokens, scopes and the policy gate the run.

    *   -   REST paths
        -   `/wp-abilities/v1/abilities`, `…/{ns}/{ability}`,
            `…/{ns}/{ability}/run`, `…/categories`, `…/categories/{slug}`.
        -   `/abilities/v1/abilities`, `…/abilities/{ns}/{name}`,
            `…/abilities/{ns}/{name}/run`, `…/categories`,
            `…/categories/{slug}`, plus `…/catalog`.
        -   Differs in one segment: describe and run sit below `/abilities/`
            because the registry itself owns the `abilities/` namespace
            (`abilities/list`) and the two would collide.

    *   -   REST run method
        -   GET for `readonly`, DELETE for `destructive`, POST otherwise.
        -   Identical, derived by :php:`AbilityDefinition::restMethod()`;
            the wrong method answers 405 with `Allow`.
        -   Matches.

    *   -   REST input
        -   GET/DELETE: `input` query parameter (JSON); POST: JSON body with
            an `input` key.
        -   The same, plus plain query parameters coerced to the schema types
            and a bare JSON object body.
        -   Matches (superset).

    *   -   Error codes
        -   `ability_invalid_input`, `ability_invalid_permissions`,
            `ability_invalid_output`, `rest_ability_not_found`,
            `rest_ability_invalid_method`, `rest_ability_category_not_found`,
            `rest_ability_cannot_execute`.
        -   The same names, plus `ability_cannot_execute`,
            `ability_policy_denied`, `ability_review_required` and
            `ability_not_found` for the governance outcomes.
        -   Matches (superset).

    *   -   Hooks
        -   `wp_before_execute_ability` (after permission, before execute),
            `wp_after_execute_ability` (after a successful run); filters
            `wp_register_ability_args`, `wp_register_ability_category_args`.
        -   PSR-14 :php:`BeforeAbilityExecutionEvent` (before the pipeline;
            may rewrite input or veto), :php:`AfterAbilityExecutionEvent`
            (every attempt, denials included),
            :php:`ModifyAbilityDefinitionEvent` (the registration filter).
        -   Differs on timing, on purpose: governance wants to see denials,
            and a veto must happen before anything is validated.

    *   -   Consumer API
        -   :php:`wp_get_ability()`, :php:`wp_get_abilities()`,
            :php:`wp_has_ability()`, :php:`$ability->execute()`,
            :php:`check_permissions()`, :php:`get_input_schema()` …
        -   :php:`AbilitiesRegistry::get()` / :php:`getDefinitions()` /
            :php:`has()` / :php:`describe()`, :php:`AbilityExecutor::execute()`,
            :php:`checkPermission()`, :php:`getInputSchema()`.
        -   Matches.

    *   -   JavaScript
        -   `@wordpress/abilities`: `getAbilities`, `getAbility`,
            `executeAbility`, `registerAbility`, `unregisterAbility`,
            `getAbilityCategories`, `registerAbilityCategory`.
        -   `@webconsulting/abilities/client.js`: `getAbilities`,
            `getAbility`, `executeAbility`, `registerAbility`,
            `unregisterAbility`, `getCategories`, `getCatalog`, tokens and
            traces.
        -   Matches except client-side category registration (categories are
            server-side here).

    *   -   MCP
        -   A separate MCP Adapter plugin exposes abilities as MCP tools.
        -   :php:`McpProjection` emits protocol-neutral tool descriptors;
            `hn/typo3-mcp-server` bridges them. No MCP SDK dependency here.
        -   Matches.

    *   -   Discovery beyond abilities
        -   —
        -   The ability catalogue: MCP tools, skills, REST and webhook
            endpoints and console commands in the ability shape, on every
            surface.
        -   TYPO3 extra.

    *   -   Governance
        -   —
        -   :file:`config/abilities-policy.yaml` (deny, review, risk cap),
            human-in-the-loop approval on CLI and in the module, execution
            traces, scoped and expiring bearer tokens, backend-group scopes,
            risk tiers and a side-effect vocabulary.
        -   TYPO3 extra.

    *   -   More surfaces
        -   —
        -   Webhooks (EXT:reactions "Run ability"), Fluid
            (:php:`AbilityProcessor`, read-only), the Scheduler (through the
            Core's "Execute console command" task).
        -   TYPO3 extra.

..  _introduction-support:

Support and source
==================

*   Source code and issues:
    `github.com/dirnbauer/typo3-abilities <https://github.com/dirnbauer/typo3-abilities>`__
*   License: GPL-2.0-or-later
