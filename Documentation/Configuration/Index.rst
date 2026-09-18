..  include:: /Includes.rst.txt

..  _configuration:

=============
Configuration
=============

..  _configuration-settings:

Extension settings
==================

:guilabel:`Admin Tools > Settings > Extension Configuration > abilities`:

..  confval:: restEnabled
    :name: abilities-restEnabled
    :type: boolean
    :Default: 1

    Serves the REST projection below :confval:`abilities-restBasePath`. Off,
    the registry stays on CLI, MCP, webhooks, Fluid and the backend module.

..  confval:: restBasePath
    :name: abilities-restBasePath
    :type: string
    :Default: /abilities/v1

    URL prefix of the REST projection, mounted before site resolution — the
    API needs no site, page or TypoScript.

..  confval:: restCorsOrigins
    :name: abilities-restCorsOrigins
    :type: string
    :Default: (empty)

    Comma-separated browser origins allowed to call the API, or `*`. Empty
    sends no CORS headers, which is right for same-origin and non-browser
    clients.

..  confval:: traceRetentionDays
    :name: abilities-traceRetentionDays
    :type: int
    :Default: 30

    Execution traces older than this are pruned opportunistically on a small
    fraction of writes. `0` keeps them forever.

..  _configuration-permissions:

Scopes and backend groups
=========================

Three layers decide whether a run happens, and all three must agree:

#.  **Scopes** — may this caller use this kind of ability at all?
#.  **TYPO3 permissions** — may this backend user touch this record, page or
    workspace? Checked by the ability's :php:`checkPermission()` and by the
    DataHandler.
#.  **The site policy** — does this installation allow the ability to run
    unattended?

A scope is a :code:`resource:operation` string (`content:read`,
`pages:write`, `workspace:publish`). Every scope an ability declares must be
granted or the run fails with `ability_invalid_permissions` naming the
missing ones. `*` grants everything, `news:*` every scope of one resource.

Scopes are granted per backend user **group**: each group has an
:guilabel:`Abilities` tab whose picker offers exactly the scopes the live
registry declares. A user's scopes are the union of all their groups',
subgroups included. Administrators always hold `*`.

A **trusted** surface (CLI without :bash:`--as-user`, MCP, Fluid) carries no
scope list and skips the check — the host already authenticated the actor.
The policy and :php:`checkPermission()` still apply everywhere.

..  _configuration-tokens:

REST tokens
===========

A token is an opaque bearer credential bound to one backend user: generated
once, stored only as a SHA-256 hash, carrying its own scope list, optionally
expiring, revocable. Its effective scopes are the **intersection** of the
token's and the user's — a token can never widen its user's rights.

..  code-block:: bash

    vendor/bin/typo3 abilities:token:create --user=editor --name="n8n production" \
        --scopes="content:read,pages:write" --expires=90
    vendor/bin/typo3 abilities:token:list
    vendor/bin/typo3 abilities:token:revoke 3

The same lifecycle is available in the module's :guilabel:`Tokens` tab. A
same-origin backend session is accepted as well; non-GET requests must then
send :code:`X-Requested-With`.

..  _configuration-policy:

The site policy
===============

:file:`config/abilities-policy.yaml` (project root, optional):

..  code-block:: yaml

    policy:
      name: "Production"
      deny:
        - "side-effect:network:outbound"   # never
      review_required:
        - "risk:high"                      # only with a human's approval
      max_risk_tier: "high"                # critical abilities never run

..  list-table:: Rule grammar (deny and review_required)
    :header-rows: 1
    :widths: 35 65

    *   -   Rule
        -   Matches

    *   -   `namespace/ability-name`
        -   exactly this ability

    *   -   `namespace/*`
        -   every ability of the namespace

    *   -   `*`
        -   every ability

    *   -   `risk:high`
        -   abilities at exactly this tier (`low`, `medium`, `high`, `critical`)

    *   -   `scope:pages:write`
        -   abilities requiring this scope (prefix match)

    *   -   `side-effect:network`
        -   abilities declaring this side effect (prefix match)

`deny` blocks outright (`ability_policy_denied`). `max_risk_tier` caps the
tier. `review_required` blocks with `ability_review_required` unless the run
carries a human approval: :bash:`--approve-review` on the CLI or the review
checkbox in the module. REST, MCP, webhooks and Fluid can never approve — a
token is not a human in the loop, so an automated client cannot delete
unattended. Order: deny → risk cap → review.

The policy is evaluated for every attempt on every surface, and every
attempt — including the denials — becomes a :sql:`tx_abilities_trace` row
(ability, surface, outcome, error code, duration, input, acting user).

..  _configuration-webhook:

Webhooks ("Run ability" reaction)
=================================

With `typo3/cms-reactions` installed, :guilabel:`System > Reactions` offers
the type :guilabel:`Run ability (abilities registry)`. Pick an ability (only
REST-exposed ones are offered) and the backend user to impersonate; the
incoming JSON payload is the input, either :code:`{"input": {...}}` or a bare
object. The run is governed like REST — the impersonated user's scopes, the
policy, :php:`checkPermission()` — traced with surface `webhook`, and
answers the REST envelope with the same status codes.

..  _configuration-fluid:

Fluid (data processor)
======================

Read-only abilities render in templates through
:php:`Webconsulting\Abilities\DataProcessing\AbilityProcessor`; every input
value goes through stdWrap and is coerced to the schema type:

..  code-block:: typoscript

    page.10 = PAGEVIEW
    page.10.dataProcessing.20 = Webconsulting\Abilities\DataProcessing\AbilityProcessor
    page.10.dataProcessing.20 {
      ability = content/search
      input {
        term.field = title
        limit = 5
      }
      as = related
    }

The template receives the result envelope: :code:`{related.ok}`,
:code:`{related.data.results}`, :code:`{related.errorCode}`. An ability
with side effects is refused with an exception — a page view must never
write. The context is trusted (the integrator wrote the TypoScript); the
policy still applies and the run is traced with surface `frontend`.

..  _configuration-scheduler:

Scheduler
=========

Every `abilities:*` command is schedulable. Use the Core's
:guilabel:`Execute console command` task with :bash:`abilities:run`, its
:bash:`--input` and, for review-gated abilities, :bash:`--approve-review` —
the person who saves the task is the reviewer.
