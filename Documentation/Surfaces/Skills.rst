..  include:: /Includes.rst.txt

..  _surfaces-skills:

======
Skills
======

An agent skill is a packaged piece of know-how: instructions plus the tools
it needs. :php:`Skills\SkillAbilityContract` is the seam between such a
skill and this registry — a skill declares the **abilities** it needs by
name, and the contract resolves them to the MCP tool identifiers an agent
client sees, or explains why they are unavailable.

..  _surfaces-skills-front-matter:

Declaring abilities in a skill
==============================

..  code-block:: yaml
    :caption: A skill's front matter

    ---
    name: publish-editorial-drafts
    description: >-
      Reviews the pending drafts of an editorial workspace with a human and
      publishes them once approved.
    abilities:
      - workspace/publish
      - content/search
    ---

..  _surfaces-skills-resolving:

Resolving tool names
====================

..  code-block:: php

    use Webconsulting\Abilities\Skills\SkillAbilityContract;

    public function __construct(private readonly SkillAbilityContract $contract) {}

    public function toolNames(array $declared): array
    {
        return $this->contract->resolveMcpToolNames($declared);
    }

returns a map of ability name to client tool name:

..  code-block:: php

    [
        'workspace/publish' => 'mcp__typo3__ability_workspace_publish',
        'content/search'    => 'mcp__typo3__ability_content_search',
    ]

Abilities the installation does not have are omitted — use
:php:`validate()` to find out why.

..  _surfaces-skills-validating:

Validating a declaration
========================

..  code-block:: php

    $findings = $this->contract->validate(['workspace/publish', 'news/write-everything']);

:php:`validate()` returns an empty array when every declared ability is
actually usable from an MCP session. Otherwise each finding carries the
ability, a code and a human-readable message:

..  list-table::
    :header-rows: 1
    :widths: 25 75

    *   -   Code
        -   Meaning

    *   -   `missing`
        -   No ability of that name is registered in this installation.

    *   -   `not_exposed`
        -   It exists but is not exposed to the `mcp` surface.

    *   -   `policy_denied`
        -   The site policy denies it outright.

    *   -   `review_required`
        -   The policy requires a human approval, which an MCP session
            cannot give. The skill can still be installed — but this step
            will need a person.

Run this when a skill is installed, not when it is first used: a skill that
cannot work on this installation should say so up front.

..  seealso::
    `webconsulting/skillflow <https://github.com/dirnbauer>`__ is the skill
    runner this contract is designed for. It is an optional companion — the
    contract itself only needs the registry.
