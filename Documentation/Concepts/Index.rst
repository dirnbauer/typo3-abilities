..  include:: /Includes.rst.txt

..  _concepts:

========
Concepts
========

..  _concepts-ability:

An ability
==========

An ability is one typed, permissioned unit of functionality. It consists of

*   **registry metadata** — the :php:`#[AsAbility]` attribute: name, title,
    description, category, scopes, risk tier, side effects, annotations and
    the surfaces it may be projected on;
*   **a contract** — :php:`getInputSchema()` and :php:`getOutputSchema()`,
    JSON Schema, possibly computed at runtime;
*   **a permission check** — :php:`checkPermission()`, for everything that
    depends on the concrete input or the TYPO3 user;
*   **behaviour** — :php:`execute()`.

Names are :code:`namespace/ability-name` in lowercase kebab-case. The
namespace groups abilities by the thing they act on (`content`, `workspace`,
`system`), not by the extension that ships them.

..  _concepts-registry:

The registry
============

:php:`Registry\AbilitiesRegistry` collects every service implementing
:php:`AbilityInterface` (auto-tagged `abilities.ability`) at container
compile time, sorted by name, duplicates rejected. It is the single source
of truth every projection reads.

..  _concepts-pipeline:

The execution pipeline
======================

Every surface calls the same :php:`Execution\AbilityExecutor`, which runs
these steps in this order:

..  code-block:: text

    BeforeAbilityExecutionEvent   listeners may rewrite the input or veto
      ↓
    policy gate                   deny rules, max risk tier, review_required
      ↓
    input validation              against the input schema, defaults applied
      ↓
    scope check                   every declared scope must be granted
      ↓
    permission check              the ability's own checkPermission()
      ↓
    execute
      ↓
    output validation             against the output schema
      ↓
    AfterAbilityExecutionEvent    always — denials and failures included

The order mirrors the WordPress Abilities API (validate → permission →
execute → validate) with the policy gate in front, because governance
outranks contracts: an ability the site has forbidden must not even see the
input.

..  _concepts-result:

The result envelope
===================

Every run returns the same shape, on every surface:

..  code-block:: json

    {"ok": true, "data": {"uid": 42}}

..  code-block:: json

    {"ok": false, "errorCode": "ability_invalid_input", "error": "$.input: missing required property \"title\""}

..  _concepts-error-codes:

Error codes
-----------

..  list-table::
    :header-rows: 1
    :widths: 34 10 56

    *   -   Code
        -   HTTP
        -   Meaning

    *   -   `ability_policy_denied`
        -   403
        -   Blocked by the site policy or vetoed by a listener. Nothing ran.

    *   -   `ability_review_required`
        -   409
        -   The policy wants a human approval this context does not carry.
            Nothing ran.

    *   -   `ability_invalid_input`
        -   400
        -   The input violates the input schema. Nothing ran.

    *   -   `ability_invalid_permissions`
        -   403
        -   A required scope is missing, or :php:`checkPermission()` denied.
            Nothing ran.

    *   -   `ability_cannot_execute`
        -   500
        -   The ability threw.

    *   -   `ability_invalid_output`
        -   500
        -   The ability ran — side effects may have happened — but returned
            data that violates its output contract.

    *   -   `ability_not_found`
        -   404
        -   No such ability, or it is not exposed to this surface.

..  _concepts-context:

The execution context
=====================

:php:`Domain\ExecutionContext` says who is running an ability, from where,
with which grants:

..  confval:: surface
    :name: context-surface
    :type: string

    `cli`, `mcp`, `rest`, `backend` or `php`. Recorded in every trace.

..  confval:: grantedScopes
    :name: context-grantedScopes
    :type: list of strings, or null

    :php:`null` marks a **trusted** surface: the host already authenticated
    the actor, so scope checks are skipped — policy and
    :php:`checkPermission()` still apply. A list is an explicit grant;
    every scope the ability declares must be present. `*` grants
    everything, `news:*` every scope of the `news` resource.

..  confval:: reviewApproved
    :name: context-reviewApproved
    :type: boolean

    A human approved this specific run. Only the CLI and the backend module
    can set it.

..  confval:: backendUserUid
    :name: context-backendUserUid
    :type: int or null

    The acting TYPO3 backend user, recorded in the trace.

..  _concepts-risk:

Risk tiers and side effects
===========================

The **risk tier** (`low`, `medium`, `high`, `critical`) and the
**side-effect** vocabulary (`database:write`, `network:outbound`,
`mail:send`, `filesystem:write`, …) are shared with TYPO3 capability
manifests. They exist so a policy can reason about abilities it has never
seen: "nothing above `high`", "nothing that calls out to the network",
"every `high` ability needs a human".

An ability that declares no side effects is read-only by default, and
read-only abilities are served with GET. Declaring side effects honestly is
therefore not paperwork — it decides how the ability is exposed.

..  _concepts-traces:

Traces
======

:php:`Trace\TraceRecorder` listens to :php:`AfterAbilityExecutionEvent` and
writes one :sql:`tx_abilities_trace` row per attempt — including the denied
ones, which are the interesting ones for governance. Tracing never breaks
execution: if the table is missing or the write fails, the run still
succeeds.

Read them in the :guilabel:`Traces` tab of the backend module.
