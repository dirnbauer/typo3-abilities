..  include:: /Includes.rst.txt

..  _developer:

=========
Developer
=========

..  _developer-register:

Registering an ability
======================

A class with the :php:`#[AsAbility]` attribute that implements
:php:`AbilityInterface` (or extends :php:`AbstractAbility`). The DI container
tags it `abilities.ability` and the registry collects it — no registration
call, no init hook:

..  code-block:: php
    :caption: EXT:my_extension/Classes/Ability/CreateArticleAbility.php

    #[AsAbility(
        name: 'news/create-article',
        title: 'Create news article',
        description: 'Creates a news article as a hidden draft below a storage page.',
        category: 'content',
        scopes: ['news:write'],
        riskTier: RiskTier::Medium,
        sideEffects: ['database:write'],
        instructions: 'Pass the title and the storage page uid. The article is created hidden; an editor publishes it.',
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
            return ['type' => 'object', 'required' => ['uid'], 'properties' => ['uid' => ['type' => 'integer']]];
        }

        public function checkPermission(array $input, ExecutionContext $context): bool|string
        {
            $user = BackendUserContext::current();
            if ($user === null) {
                return BackendUserContext::missingUserMessage('news/create-article');
            }
            $page = BackendUtility::getRecord('pages', (int)$input['pid']);
            if ($page === null || !$user->doesUserHaveAccess($page, Permission::CONTENT_EDIT)) {
                return sprintf('Backend user may not edit content on page #%d.', (int)$input['pid']);
            }

            return true;
        }

        public function execute(array $input, ExecutionContext $context): mixed
        {
            // … DataHandler, as $user …
            return ['uid' => $uid];
        }
    }

The ability now appears in :bash:`abilities:list`, the REST discovery, the
catalogue, as MCP tool `ability_news_create-article`, in the webhook picker
and in the backend module.

..  list-table:: :php:`#[AsAbility]` arguments
    :header-rows: 1
    :widths: 18 14 68

    *   -   Argument
        -   Default
        -   Meaning

    *   -   `name`
        -   required
        -   `namespace/ability-name`, lowercase kebab-case; invalid names
            fail at container compile time.

    *   -   `title`, `description`
        -   required
        -   Written for agents as much as for humans; part of the MCP tool
            description and the catalogue.

    *   -   `category`
        -   `general`
        -   Built in: `system`, `content`, `site`, `search`, `workspace`,
            `forms`, `registry`, `demo`, `general`. Unknown slugs are logged.

    *   -   `scopes`
        -   `[]`
        -   `resource:operation` strings a caller must hold.

    *   -   `riskTier`
        -   `Low`
        -   `Low`, `Medium`, `High`, `Critical`; policies cap and match it.

    *   -   `sideEffects`
        -   `[]`
        -   `database:write`, `network:outbound`, `mail:send`,
            `filesystem:write`, … Empty means read-only.

    *   -   `readOnly`
        -   derived
        -   Explicit override of "no side effects". Read-only abilities run
            with GET and may render in Fluid.

    *   -   `destructive`, `idempotent`
        -   `false`
        -   MCP `destructiveHint` / `idempotentHint`; destructive abilities
            run with DELETE.

    *   -   `instructions`
        -   `''`
        -   When to call it, what to do first, what to tell the human.

    *   -   `expose`
        -   `['mcp', 'cli', 'rest']`
        -   Surfaces that may project it. Webhooks follow `rest`; the
            module shows everything.

    *   -   `meta`
        -   `[]`
        -   Free-form, passed through to every projection.

Schemas are plain arrays in a JSON Schema subset validated without a
third-party library: `type` (incl. unions), `properties`, `required`,
`additionalProperties`, `items`, `enum`, `minimum`, `maximum`, `minLength`,
`maxLength`, `pattern`, `minItems`, `maxItems`, `default`. Set
:php:`'additionalProperties' => false` so a typo in an agent's arguments
fails loudly, and give optional properties a `default` — defaults are applied
before validation and pre-fill the module's form.

Categories: :php:`#[AsAbilityCategory(slug: 'news', label: 'News')]` on any
DI-managed class, or an :php:`AbilityCategoryProviderInterface` service.

..  _developer-pipeline:

The execution pipeline
======================

Every surface calls :php:`Execution\AbilityExecutor::execute($ability,
$input, $context, $definition)`:

..  code-block:: text

    BeforeAbilityExecutionEvent   listeners may rewrite the input or veto
    policy gate                   deny → risk cap → review_required
    input validation              schema, defaults applied first
    scope check                   every declared scope must be granted
    permission check              the ability's checkPermission()
    execute
    output validation
    AfterAbilityExecutionEvent    always — denials and failures included

..  list-table:: Result envelope and error codes
    :header-rows: 1
    :widths: 32 10 58

    *   -   `errorCode`
        -   HTTP
        -   Meaning

    *   -   `ability_policy_denied`
        -   403
        -   Denied by the policy or vetoed by a listener; nothing ran.

    *   -   `ability_review_required`
        -   409
        -   The policy wants a human approval this context lacks; nothing ran.

    *   -   `ability_invalid_input`
        -   400
        -   Input violates the schema; nothing ran.

    *   -   `ability_invalid_permissions`
        -   403
        -   A scope is missing or :php:`checkPermission()` denied; nothing ran.

    *   -   `ability_cannot_execute`
        -   500
        -   The ability threw.

    *   -   `ability_invalid_output`
        -   500
        -   The ability ran (side effects may have happened) but violated
            its output contract.

    *   -   `ability_not_found`
        -   404
        -   Unknown ability, or not exposed to this surface.

:php:`Domain\ExecutionContext` carries `surface` (`cli`, `mcp`, `rest`,
`webhook`, `backend`, `frontend`, `php`), `grantedScopes` (:php:`null` =
trusted, a list = explicit grants), `reviewApproved` and `backendUserUid`.
Factories: :php:`cli()`, :php:`mcp()`, :php:`rest()`, :php:`webhook()`,
:php:`backend()`, :php:`frontend()`.

..  _developer-events:

PSR-14 events
=============

..  code-block:: php

    #[AsEventListener(identifier: 'my-extension/agent-drafts')]
    public function __invoke(BeforeAbilityExecutionEvent $event): void
    {
        if ($event->definition->name !== 'content/create-page-draft') {
            return;
        }
        if ($event->context->surface === ExecutionContext::SURFACE_MCP && $event->definition->riskTier === RiskTier::Critical) {
            $event->deny('Critical abilities are not available to agents on this site.');   // → ability_policy_denied
        }
        $input = $event->getInput();
        $input['title'] = '[Agent] ' . $input['title'];
        $event->setInput($input);
    }

*   :php:`BeforeAbilityExecutionEvent` — before the pipeline: `definition`,
    `context`, :php:`getInput()` / :php:`setInput()`, :php:`deny($reason)`.
*   :php:`AfterAbilityExecutionEvent` — after every attempt: `definition`,
    `context`, `input` (the caller's raw input), `result`, `durationMs`. The
    built-in :php:`Trace\TraceRecorder` is such a listener.
*   :php:`ModifyAbilityDefinitionEvent` — while the registry is built, once
    per ability: :php:`setExpose()`, :php:`setRiskTier()`,
    :php:`setReadOnly()`, :php:`setDefinition()` (same name and class). The
    registration filter: hide an ability from agents or raise its risk on
    this installation without touching the class. Listeners must not depend
    on the registry itself.

..  _developer-catalog:

Catalogue sources
=================

The capability catalogue (:php:`Catalog\CapabilityCatalog`) is the union of
every :php:`Catalog\CapabilitySourceInterface` service (tagged
`abilities.capability_source`). Shipped sources: `AbilitiesSource`,
`McpToolSource` (hn/typo3-mcp-server), `SkillSource` (nr-llm, skillflow),
`RestEndpointSource` (the REST projection, EXT:reactions, sg-apicore) and
`CliCommandSource`. Optional dependencies are nullable constructor arguments
behind `class_exists()` guards, so a missing extension yields nothing instead
of failing.

..  code-block:: php

    final class FormsSource implements CapabilitySourceInterface
    {
        public function getSource(): string
        {
            return 'forms';
        }

        public function getCapabilities(): iterable
        {
            foreach ($this->formPersistence->listForms() as $form) {
                yield new CapabilityEntry(
                    id: 'form/' . $form['identifier'],
                    title: $form['name'],
                    description: 'Submits the form "' . $form['name'] . '".',
                    source: 'forms',
                    surfaces: ['rest'],
                    inputSchema: $this->schemaOf($form),
                    annotations: CapabilityEntry::annotations(idempotent: false),
                    invocations: ['rest' => 'POST /forms/' . $form['identifier']],
                );
            }
        }
    }

Every entry is one :php:`CapabilityEntry`: `id` (`namespace/name`), `title`,
`description`, `source`, `surfaces`, `inputSchema` (`[]` = unknown),
`annotations` (`readonly`, `destructive`, `idempotent`), `invocations`
(surface → how) and `meta`.

..  _developer-api:

PHP API
=======

..  list-table::
    :header-rows: 1
    :widths: 45 55

    *   -   Service
        -   Purpose

    *   -   :php:`Registry\AbilitiesRegistry`
        -   :php:`has()`, :php:`get()`, :php:`getDefinition()`,
            :php:`getDefinitions($category, $surface)`, :php:`describe()`
            (definition + schemas), :php:`getDeclaredScopes()`.

    *   -   :php:`Execution\AbilityExecutor`
        -   :php:`execute($ability, $input, $context, $definition)`; pass the
            registry's definition so :php:`ModifyAbilityDefinitionEvent`
            overrides apply.

    *   -   :php:`Catalog\CapabilityCatalog`
        -   :php:`sources()`, :php:`entries($source, $surface, $search)`,
            :php:`toArray()`.

    *   -   :php:`Projection\Mcp\McpProjection`
        -   :php:`descriptors()`, :php:`descriptor($toolName)`,
            :php:`resolveAbilityName()`, :php:`execute($toolName, $arguments,
            $context)` — what an MCP server bridges.

    *   -   :php:`Skills\SkillAbilityContract`
        -   :php:`resolveMcpToolNames()`, :php:`validate()`.

    *   -   :php:`Security\TokenService`
        -   :php:`create()`, :php:`authenticate()`, :php:`list()`, :php:`revoke()`.

    *   -   :php:`Permission\BackendUserScopeResolver`
        -   Scopes of a backend user or user record; :php:`intersect()`.

The public API is: the :php:`#[AsAbility]` / :php:`#[AsAbilityCategory]`
attributes, the interfaces and value objects in `Registry\`, `Domain\`,
`Event\` and `Catalog\`, the services above, the CLI command signatures, the
REST paths and envelopes, and the `client.js` exports. Semantic versioning
applies to them.

..  _developer-develop:

Developing the extension
========================

..  code-block:: bash

    composer install                 # into .Build/
    composer ci                      # cgl + phpstan (level 8) + unit + functional (sqlite)
    composer test:functional         # typo3DatabaseDriver=mysqli … for MariaDB
    docker run --rm -v $PWD:/project ghcr.io/typo3-documentation/render-guides:latest --config=Documentation

Functional tests load EXT:reactions and EXT:workspaces from the Core and two
fixture extensions (a PSR-14 listener, the skill tables of nr-llm and
skillflow). PHPStan analyses the optional MCP-server and sg-apicore symbols
from :file:`Build/phpstan/OptionalDependencies.php`.
