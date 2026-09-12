..  include:: /Includes.rst.txt

..  _policy:

======
Policy
======

The policy is the installation's own answer to "what may run here, and what
needs a person first". It is a single YAML file in the project root, read by
:php:`Policy\PolicyProvider`:

..  code-block:: text

    config/abilities-policy.yaml

Without the file everything is allowed — the policy layer is opt-in, while
scopes and each ability's permission check always apply.

..  _policy-example:

The shipped example
===================

:file:`Resources/Private/Examples/abilities-policy.yaml` denies nothing and
puts every high-risk ability behind a human review:

..  code-block:: yaml
    :caption: config/abilities-policy.yaml

    policy:
      name: "Default abilities policy"
      deny: []
      review_required:
        - "risk:high"
      # max_risk_tier: "high"   # uncomment to keep "critical" abilities from ever running

..  _policy-rules:

Rule grammar
============

`deny` and `review_required` are lists of rules. A rule matches an ability
by one of these forms:

..  list-table::
    :header-rows: 1
    :widths: 30 70

    *   -   Rule
        -   Matches

    *   -   :code:`content/delete-page`
        -   exactly that ability

    *   -   :code:`content/*`
        -   every ability of the `content` namespace

    *   -   :code:`*`
        -   every ability

    *   -   :code:`risk:high`
        -   abilities at exactly that risk tier

    *   -   :code:`scope:pages:write`
        -   abilities requiring that scope (prefix match, so
            :code:`scope:pages` matches `pages:write` and `pages:read`)

    *   -   :code:`side-effect:network`
        -   abilities declaring that side effect (prefix match, so
            :code:`side-effect:network` matches `network:outbound`)

..  confval:: policy.name
    :name: policy-name
    :type: string
    :Default: 'unnamed policy'

    Shown in every denial message, so an editor reading "denied by policy
    *Production*" knows which document to argue with.

..  confval:: policy.deny
    :name: policy-deny
    :type: list of rules
    :Default: []

    Matching abilities never run, on any surface. `deny` always wins — no
    approval overrides it.

..  confval:: policy.review_required
    :name: policy-review-required
    :type: list of rules
    :Default: []

    Matching abilities only run when the execution context carries an
    explicit human approval.

..  confval:: policy.max_risk_tier
    :name: policy-max-risk-tier
    :type: string (low, medium, high, critical)
    :Default: (none)

    Abilities above this tier are denied outright. An invalid value throws
    rather than being ignored — a typo in a governance file must not
    silently disable it.

..  _policy-review:

Human review
============

`review_required` is the human-in-the-loop mechanism. A matching run is
denied with `ability_review_required` (HTTP 409) unless the surface passes
an explicit approval:

..  list-table::
    :header-rows: 1
    :widths: 30 70

    *   -   Surface
        -   How to approve

    *   -   CLI
        -   :bash:`abilities:run … --approve-review`

    *   -   Backend module
        -   the :guilabel:`Approve review` checkbox in the Run tab

    *   -   REST
        -   **not possible** — a bearer token is not a human

    *   -   MCP
        -   **not possible** — an agent session is not a human

Both refusals are the point of the feature. An unattended client that could
approve its own review would not be a review.

..  _policy-examples:

Worked examples
===============

..  code-block:: yaml
    :caption: A cautious production site

    policy:
      name: "Production"
      deny:
        - "side-effect:network:outbound"   # nothing calls out from here
        - "experimental/*"
      review_required:
        - "risk:high"
        - "scope:workspace:publish"
      max_risk_tier: "high"                # critical abilities never run

..  code-block:: yaml
    :caption: A staging site where agents may work freely

    policy:
      name: "Staging"
      deny: []
      review_required: []

..  _policy-precedence:

Order of evaluation
===================

#.  `deny` — first match wins, and nothing overrides it.
#.  `max_risk_tier` — the ability's tier is compared against the cap.
#.  `review_required` — first match wins, unless the context is approved.

The gate runs **before** input validation, so a forbidden ability never even
sees the caller's data.

..  _policy-skills:

Policy and skills
=================

:php:`Skills\SkillAbilityContract::validate()` applies the same policy when
a skill is installed, so a skill that declares an ability this site forbids
says so up front instead of failing halfway through a task — see
:ref:`surfaces-skills-validating`.
