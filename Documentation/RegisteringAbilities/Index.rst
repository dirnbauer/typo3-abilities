..  include:: /Includes.rst.txt

..  _registering-abilities:

=====================
Registering abilities
=====================

..  _registering-abilities-minimal:

A minimal ability
=================

Create a class, implement :php:`AbilityInterface` (or extend
:php:`AbstractAbility`) and carry the :php:`#[AsAbility]` attribute. The DI
container does the rest: the class is auto-tagged `abilities.ability` and
collected into the registry.

..  code-block:: php
    :caption: EXT:my_extension/Classes/Ability/CreateArticleAbility.php

    <?php

    declare(strict_types=1);

    namespace MyVendor\MyExtension\Ability;

    use Webconsulting\Abilities\Attribute\AsAbility;
    use Webconsulting\Abilities\Domain\ExecutionContext;
    use Webconsulting\Abilities\Domain\RiskTier;
    use Webconsulting\Abilities\Registry\AbstractAbility;

    #[AsAbility(
        name: 'news/create-article',
        title: 'Create news article',
        description: 'Creates a news article as a hidden draft.',
        category: 'content',
        scopes: ['news:write'],
        riskTier: RiskTier::Medium,
        sideEffects: ['database:write'],
        instructions: 'Pass a title and the storage page uid. The article is created hidden; an editor publishes it.',
    )]
    final class CreateArticleAbility extends AbstractAbility
    {
        public function getInputSchema(): array
        {
            return [
                'type' => 'object',
                'required' => ['title', 'pid'],
                'additionalProperties' => false,
                'properties' => [
                    'title' => ['type' => 'string', 'minLength' => 3, 'maxLength' => 255],
                    'pid' => ['type' => 'integer', 'minimum' => 1],
                    'bodytext' => ['type' => 'string', 'default' => ''],
                ],
            ];
        }

        public function getOutputSchema(): array
        {
            return [
                'type' => 'object',
                'required' => ['uid'],
                'properties' => ['uid' => ['type' => 'integer']],
            ];
        }

        public function execute(array $input, ExecutionContext $context): mixed
        {
            // … create the record, workspace-aware, through the DataHandler …

            return ['uid' => $uid];
        }
    }

That is the whole registration. The ability now appears in
:bash:`abilities:list`, in the REST discovery endpoint, as the MCP tool
`ability_news_create-article` and in the backend module.

..  _registering-abilities-attribute:

The :php:`#[AsAbility]` arguments
=================================

..  confval:: name
    :name: asability-name
    :type: string
    :Required: true

    Unique :code:`namespace/ability-name`, lowercase kebab-case on both
    sides. Invalid names throw at container compile time, not at runtime.

..  confval:: title
    :name: asability-title
    :type: string
    :Required: true

    Short human-readable label.

..  confval:: description
    :name: asability-description
    :type: string
    :Required: true

    What the ability does. Written for agents as much as for humans — it is
    part of the MCP tool description.

..  confval:: category
    :name: asability-category
    :type: string
    :Default: 'general'

    Category slug. Built in: `system`, `content`, `site`, `search`,
    `workspace`, `forms`, `registry`, `demo`, `general`. Unknown slugs are
    logged, not fatal.

..  confval:: scopes
    :name: asability-scopes
    :type: list of strings
    :Default: []

    Scopes a caller must hold, :code:`resource:operation` by convention
    (`news:write`, `pages:read`). Every one of them must be granted or the
    run is denied with `ability_invalid_permissions`.

..  confval:: riskTier
    :name: asability-riskTier
    :type: RiskTier
    :Default: RiskTier::Low

    `Low`, `Medium`, `High` or `Critical`. Policies cap it and match on it.

..  confval:: sideEffects
    :name: asability-sideEffects
    :type: list of strings
    :Default: []

    Subsystems the ability touches: `database:write`, `network:outbound`,
    `mail:send`, `filesystem:write`, … An empty list means read-only.

..  confval:: idempotent
    :name: asability-idempotent
    :type: boolean
    :Default: false

    Running it again with the same input is safe. Projected onto the MCP
    `idempotentHint`.

..  confval:: destructive
    :name: asability-destructive
    :type: boolean
    :Default: false

    Deletes or irreversibly alters data. Projected onto the MCP
    `destructiveHint` — and onto the REST method, which becomes DELETE.

..  confval:: readOnly
    :name: asability-readOnly
    :type: boolean or null
    :Default: null

    Explicit read-only annotation. :php:`null` derives it from
    :confval:`asability-sideEffects` (empty = read-only), which keeps the
    annotation and the side-effect list from disagreeing. Read-only
    abilities are served with GET.

..  confval:: instructions
    :name: asability-instructions
    :type: string
    :Default: ''

    Usage guidance for agents: when to call this, what to do first, what to
    tell the human. Appended to the MCP tool description.

..  confval:: expose
    :name: asability-expose
    :type: list of strings
    :Default: ['mcp', 'cli', 'rest']

    Which surfaces may project this ability. Drop `rest` to keep an ability
    off the API, drop `mcp` to keep it away from agents. The backend module
    deliberately ignores this and shows everything — it is an
    administrator's inspector.

..  confval:: meta
    :name: asability-meta
    :type: array
    :Default: []

    Free-form metadata, passed through to every projection.

..  _registering-abilities-schemas:

Input and output schemas
========================

Schemas are plain PHP arrays in a documented JSON Schema subset, validated
by a dependency-free validator. Supported keywords: `type` (including union
arrays), `properties`, `required`, `additionalProperties` (boolean),
`items`, `enum`, `minimum`, `maximum`, `minLength`, `maxLength`, `pattern`,
`minItems`, `maxItems` and `default` for top-level object properties.

Two habits pay off:

*   Set :code:`'additionalProperties' => false`. A typo in an agent's
    arguments then fails loudly instead of being silently ignored.
*   Give optional properties a `default`. Defaults are applied *before*
    validation, so :php:`execute()` never sees a missing key, and the
    backend module's generated form pre-fills them.

Returning :php:`[]` from either method disables validation for that side.

..  _registering-abilities-permission:

Permission checks
=================

Scopes answer "may this caller use this kind of ability at all". They cannot
answer "may this user edit *this* page" — that needs the input. That is what
:php:`checkPermission()` is for; it runs on every surface, trusted or not,
after the scope check and before :php:`execute()`.

..  code-block:: php

    public function checkPermission(array $input, ExecutionContext $context): bool|string
    {
        $user = $GLOBALS['BE_USER'] ?? null;
        if (!$user instanceof BackendUserAuthentication) {
            return 'This ability needs an authenticated backend user.';
        }
        if ($user->isAdmin()) {
            return true;
        }
        $page = BackendUtility::getRecord('pages', (int)$input['pid']);
        if ($page === null || !$user->doesUserHaveAccess($page, Permission::CONTENT_EDIT)) {
            return sprintf('Backend user may not edit content on page #%d.', (int)$input['pid']);
        }

        return true;
    }

Return :php:`true` to allow, :php:`false` to deny, or a **string** with the
reason — the string reaches the caller and is what makes a denial
actionable instead of mysterious.

..  _registering-abilities-categories:

Registering a category
======================

For a static category, put the attribute on any DI-managed class:

..  code-block:: php

    use Webconsulting\Abilities\Category\AsAbilityCategory;

    #[AsAbilityCategory(slug: 'news', label: 'News', description: 'Editorial news operations')]
    final class NewsAbilityCategories {}

For categories that are computed or translated, implement
:php:`AbilityCategoryProviderInterface` instead; the service is collected
automatically.

..  _registering-abilities-executing:

Executing an ability from PHP
=============================

Injecting the executor and the registry runs an ability through the same
governed pipeline every surface uses:

..  code-block:: php

    use Webconsulting\Abilities\Domain\ExecutionContext;
    use Webconsulting\Abilities\Execution\AbilityExecutor;
    use Webconsulting\Abilities\Registry\AbilitiesRegistry;

    public function __construct(
        private readonly AbilitiesRegistry $registry,
        private readonly AbilityExecutor $executor,
    ) {}

    public function run(): void
    {
        $result = $this->executor->execute(
            $this->registry->get('news/create-article'),
            ['title' => 'Hello', 'pid' => 12],
            new ExecutionContext(ExecutionContext::SURFACE_PHP, ['news:write']),
            $this->registry->getDefinition('news/create-article'),
        );

        if (!$result->ok) {
            // $result->errorCode, $result->error
        }
    }

Passing the definition from the registry matters: it is the one that
:php:`ModifyAbilityDefinitionEvent` listeners have already adjusted.
