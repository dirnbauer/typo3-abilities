# Changelog

All notable changes to `webconsulting/typo3-abilities` are documented here.
This project follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/)
and [Semantic Versioning](https://semver.org/).

## 1.3.0 — 2026-09-23

A maintenance release: dependencies, PHP 8.4 idioms and a backend module that
reads like the rest of TYPO3 v14, in English and German. **No public surface
changed** — class names, constructor signatures, ability identifiers, CLI
commands, AJAX routes, REST paths, MCP tool names, database tables and the
`client.js` exports are the same as in 1.2.0.

### Changed

- Backend module: every string of `registry.js` comes from the v14
  `~labels/abilities.mod` label module (notifications, statuses,
  confirmations, empty rows, badges), and the risk-tier and annotation badges
  of the template are XLIFF labels; German for all of them.
- Catalogue tab: the description has its own wrapping column (it overflowed
  the non-wrapping row header before) and the per-surface invocations fold
  into a `<details>` element.
- Intros and the empty registry use core infoboxes; the one-time REST token
  sits in a warning infobox with a copy button; the token form stacks its
  fields.
- `module.css` only uses custom properties that exist in the v14 backend (no
  hex fallbacks, no custom focus outline — core provides it);
  `text-body-secondary`, which the backend CSS does not define, became
  `text-muted`; required run fields carry `aria-required`; dates follow the
  backend language.
- The module registration uses the v14 label keys (`title`,
  `short_description`, `description`) in
  `Resources/Private/Language/Modules/abilities.xlf`.
- PHP 8.4 idioms: typed class constants, `readonly` on the 25 final classes
  whose state already was, `new Foo()->bar()`, `array_any()` and first-class
  callables.
- Requirements follow what TYPO3 14.3 installs: `psr/http-message` ^2.0,
  `symfony/console` and `symfony/yaml` ^7.4; dev: `phpunit/phpunit` ^13.3,
  `typo3/testing-framework` ^9.7, `phpstan/phpstan` ^2.2.
- CI requires PHP 8.4 and 8.5 for unit and functional tests (8.5 was
  experimental) and pins current action releases.

### Added

- German translation of `locallang_db.xlf` (token, trace, backend group and
  reaction TCA).
- Unit tests that every label the template and `registry.js` reference
  exists in both languages and that every label file has a complete German
  twin.
- `.gitattributes`: development files stay out of the Composer dist archive;
  `composer.json` gains `homepage` and `support` URLs.

## 1.2.0 — 2026-09-19

Terminology fix inside the catalogue layer. "Ability" is a unit of
functionality this installation can perform; "capability" is reserved for the
permission gating of the MCP capability manifest, exactly as in the WordPress
Abilities API this extension mirrors. **No public surface changed**: the CLI
commands, the REST paths, the MCP tool names, the AJAX routes and the
`client.js` exports are the same as in 1.1.0.

### Changed

- `Catalog\CapabilityCatalog` → `Catalog\AbilityCatalog`.
- `Catalog\CapabilityEntry` → `Catalog\CatalogEntry`.
- `Catalog\CapabilitySourceInterface` → `Catalog\CatalogSourceInterface`,
  and its `getCapabilities()` → `getEntries()`.
- DI tag `abilities.capability_source` → `abilities.catalog_source`.
- Wording in the backend module, the CLI summaries (`N catalogue entries …`,
  `No catalogue entries match.`), the REST catalogue endpoint title and the
  manual now says "ability catalogue".
- The manual states the vocabulary explicitly (Developer → Vocabulary, plus a
  row in the WordPress parity checklist).

### Deprecated

- The three 1.1 class names are kept as aliases
  (`Classes/Compatibility/ClassAliases.php`) and the tag
  `abilities.capability_source` is still collected. Both are removed in
  2.0.0. A custom catalogue source must rename its `getCapabilities()` method
  to `getEntries()`; the interface alias cannot do that for it.

## 1.1.0 — 2026-09-18

The registry becomes the ability catalogue of the whole installation and
gains two surfaces. Backwards compatible for consumers of the public API
(`AbilityDefinition::mcpToolName()`, the registry, the executor, the MCP
projection); the deprecated `AbilityExecutedEvent` is removed as announced.

### Added

- **Ability catalogue** (`Catalog\AbilityCatalog`): the union of
  every `CatalogSourceInterface` (tag `abilities.catalog_source`),
  each entry a `CatalogEntry` with id (`namespace/name`), title,
  description, source, surfaces, input schema, `readonly`/`destructive`/
  `idempotent` annotations and per-surface invocations. Shipped sources:
  `AbilitiesSource`, `McpToolSource` (native tools of hn/typo3-mcp-server,
  optional), `SkillSource` (`tx_nrllm_skill`, `tx_skillflow_skill`,
  optional), `RestEndpointSource` (the REST projection, EXT:reactions
  webhooks, sgalinski/sg-apicore endpoints, optional) and `CliCommandSource`
  (every console command with a schema derived from its InputDefinition).
- The catalogue on every surface: `abilities:catalog
  [--source|--surface|--search] [--format=table|json]`,
  `abilities:list --source=<slug>`, `GET {base}/catalog`, the ability
  `abilities/catalog` (MCP tool `ability_abilities_catalog`), the backend
  module's **Catalogue** tab, the AJAX route `abilities_catalog` and
  `getCatalog()` in `client.js`.
- **Webhook surface**: EXT:reactions reaction type `abilities-run`
  (`Reaction\RunAbilityReaction`, TCA field `sys_reaction.tx_abilities_ability`)
  runs a REST-exposed ability as the impersonated backend user with that
  user's scopes; surface `webhook`, never approves a review. Registered only
  when typo3/cms-reactions is installed.
- **Fluid surface**: `DataProcessing\AbilityProcessor` runs a read-only
  ability while rendering (`ability`, `input.*` through stdWrap and schema
  coercion, `as`); surface `frontend`, trusted context, refuses abilities
  with side effects.
- `ExecutionContext::webhook()`, `::frontend()`, `SURFACE_WEBHOOK`,
  `SURFACE_FRONTEND`, `PROJECTION_SURFACES`; `AbilitiesRegistry::describe()`
  (the one contract-plus-schemas shape every surface publishes);
  `SchemaValidator::coerce()` (string → declared scalar type);
  `RestInputMapper::fromPayload()`; `BackendUserContext::currentUid()`;
  `Backend\Tca\RegistryItemsProcFunc::addAbilities()`.
- Documentation rewritten as six short pages with a WordPress Abilities API
  parity checklist and a seeded walkthrough of every surface; German labels
  for the new tab and fields; localized `tx_abilities_trace` TCA.
- Tests: 186 unit and 39 functional tests (catalogue, every source, the
  reaction with EXT:reactions, the data processor, the skills source against
  fixture tables, the catalog CLI/REST/AJAX/module surfaces).

### Changed

- REST category error code is `rest_ability_category_not_found` (WordPress
  name; was `rest_category_not_found`).
- `RestRoute` carries a `RestEndpoint` enum (`Listing`, `Describe`, `Run`,
  `Categories`, `Category`, `Catalog`) instead of string constants;
  `RestAuthenticator::authenticate()` returns the `ExecutionContext`
  directly and `RestRequestHandler::handle()` takes it.
- `RestConfiguration` is a container service (factory from the extension
  configuration) injected into the middleware and the catalogue sources.
- `AbilityResult::failure()` takes an `AbilityErrorCode`; the `ERROR_*`
  constants are now defined from the enum. `AbilityExecutor`,
  `PolicyProvider` and `AfterAbilityExecutionEvent` are final.
- `Permission\ScopeItemsProcFunc` → `Backend\Tca\RegistryItemsProcFunc`;
  `Ability\Support\BackendUserContext` → `Permission\BackendUserContext`;
  the token commands moved to `Projection\Cli\`.
- Toolchain: Composer installs into `.Build/` (`composer ci`, `cgl`,
  `phpstan`, `test`); PHPStan reads optional-dependency symbols from
  `Build/phpstan/OptionalDependencies.php`; CI lints JavaScript too.
- `typo3/cms-frontend` is a runtime requirement (data processor).

### Removed

- `Event\AbilityExecutedEvent` (deprecated in 1.0.0) — listen to
  `AfterAbilityExecutionEvent`.
- Dead code: `RestIdentity`, `AbilityDefinition::category()`/`instructions()`/
  `REST_METHOD_*`, `ExecutionContext::isTrusted()`/`withReviewApproved()`,
  `AbilityResult::errorCodeEnum()`, `CategoryRegistry::slugs()`, the
  module's unused `ajaxUrls` JSON island (TYPO3 exposes AJAX routes itself),
  the `button.reload` label, `ext_emconf.php`, the empty `ext_localconf.php`,
  `Tests/bootstrap.php` and the stale `examples/abilities-studio.html`
  (it targeted the pre-1.0 `/api/abilities/v1` path).

## 1.0.0 — 2026-09-12

First stable release: registry, categories, annotations, events, permissions,
tokens, REST, a protocol-neutral MCP projection, four demo abilities, the
four-tab backend module and the full manual.

### Breaking

- **`Projection\Mcp\AbilityMcpTool` is removed**, together with the compiler
  pass that tagged one `mcp.tool` service per ability and the test stubs for
  `Hn\McpServer` / `Mcp\Types`. The MCP bridge now lives in the MCP server
  extension (`hn/typo3-mcp-server`), which consumes
  `Projection\Mcp\McpProjection::descriptors()` / `execute()`. This extension
  no longer references any MCP SDK symbol.
- **REST moved here from the sg_apicore fork.** The projection is served by
  this extension at `/abilities/v1` (extension setting `restBasePath`),
  authenticated with abilities tokens (`abilities:token:create`) or a
  same-origin backend session — not by API Core routes any more.
- **Error codes renamed** to the WordPress Abilities API vocabulary:
  `invalid_input` → `ability_invalid_input`, `permission_denied` →
  `ability_invalid_permissions`, `invalid_output` → `ability_invalid_output`,
  `execution_error` → `ability_cannot_execute`, `policy_denied` →
  `ability_policy_denied`; new `ability_review_required` (a `review_required`
  policy rule without approval) and `ability_not_found`. The
  `AbilityResult::ERROR_*` constants keep their names.
- `Event\AbilityExecutedEvent` is deprecated in favour of
  `Event\AfterAbilityExecutionEvent`; it remains as a subclass and is still
  dispatched for one release, removal in 1.1.0.
- The backend module and its AJAX routes execute with the backend user's
  resolved scopes (`be_groups.tx_abilities_scopes`, admins `*`) instead of a
  trusted null-scope context.

### Added

- `#[AsAbility]` annotations `readOnly` (explicit, else derived from empty
  `sideEffects`) and `instructions`; `AbilityDefinition::annotations()`,
  `restMethod()`, `with()`.
- Categories: `#[AsAbilityCategory]`, `AbilityCategoryProviderInterface`,
  `CategoryRegistry` with the built-in vocabulary (system, content, site,
  search, workspace, forms, registry, demo, general). Unknown categories are
  logged, not fatal.
- Events: `BeforeAbilityExecutionEvent` (rewrite input, veto),
  `AfterAbilityExecutionEvent`, `ModifyAbilityDefinitionEvent` (expose,
  risk tier, read-only overrides per installation).
- Permissions: `be_groups.tx_abilities_scopes`, `BackendUserScopeResolver`,
  `ExecutionContext::$backendUserUid`, wildcard grants (`*`, `resource:*`),
  CLI `abilities:run --as-user=<username>`.
- Tokens: `tx_abilities_token` (SHA-256 hashes only), `TokenService`,
  `abilities:token:create|list|revoke`.
- REST projection (`Http\RestMiddleware`): list/describe/run/categories with
  run methods derived from the annotations (GET / POST / DELETE), `X-Total`
  pagination headers, CORS (`restCorsOrigins`), 409 for review-gated runs.
- MCP: `McpProjection`, `McpToolDescriptor`; registry abilities
  `abilities/list` and `abilities/describe` (MCP tools
  `ability_abilities_list`, `ability_abilities_describe`).
- Skills: `Skills\SkillAbilityContract::resolveMcpToolNames()` / `validate()`.
- Extension settings `restEnabled`, `restBasePath`, `restCorsOrigins`,
  `traceRetentionDays` (traces are pruned opportunistically).

#### Demo abilities

- `content/search` — read-only (`content:read`), workspace-aware search over
  pages and content elements; demonstrates a GET run whose query parameters
  are coerced to the schema types. Hidden records are found and flagged but
  carry no URL, because the page router cannot route them.
- `content/create-page-draft` — write, risk `medium` (`pages:write`,
  `database:write`); creates a hidden page through the DataHandler as the
  acting backend user, so permissions, slug generation and the user's
  workspace apply. Demonstrates the Before/After execution events.
- `content/delete-page` — destructive, risk `high`, `idempotent: false`;
  soft-deletes through the DataHandler, refuses a branch without
  `recursive`. Demonstrates DELETE over REST and the review gate.
- `workspace/publish` — risk `high`, `idempotent: true`; `dryRun` (the
  default) lists the pending records, the real run publishes them with the
  Workspaces command map. EXT:workspaces stays optional.
- `Resources/Private/Examples/abilities-policy.yaml`: a policy example that
  denies nothing and puts `risk:high` behind a human review.

#### Backend module

- Four tabs: **Registry** (filter by category, surface and risk tier; shows
  annotations, scopes and side effects), **Run** (a form generated from the
  ability's `inputSchema`, with the review checkbox shown only when the
  policy asks for one, and the result plus its trace uid), **Traces** (the
  newest `tx_abilities_trace` rows with filters) and **Tokens** (list,
  create with scopes and expiry showing the plaintext once, revoke).
- AJAX routes `abilities_list`, `abilities_describe`, `abilities_run`,
  `abilities_categories`, `abilities_tokens`, `abilities_token_create`,
  `abilities_token_revoke`, `abilities_traces`; `list` and `describe` carry
  the policy decision so the module can gate a run before it happens.
- `Trace\TraceRepository` for reading traces; `TraceRecorder::lastTraceUid()`
  so a run can point at its own trace row.
- ES module `@webconsulting/abilities/client.js` with `getAbilities()`,
  `getAbility()`, `executeAbility()`, `getCategories()`, `getTokens()`,
  `createToken()`, `revokeToken()`, `getTraces()` and a client-side ability
  registry (`registerAbility()`).
- Icons redrawn in the TYPO3 v14 style: `module.svg` on the 64 grid with
  `currentColor` and `var(--icon-color-accent)`, plus `record-token.svg` and
  `record-trace.svg` for the two tables.
- German translations for every module label.

#### Documentation and quality

- Full manual in `Documentation/` (TYPO3 RST, `guides.xml` release 1.0.0),
  including a side-by-side comparison with the WordPress Abilities API.
- Test suites: unit (`Build/phpunit/UnitTests.xml`) and functional
  (`Build/phpunit/FunctionalTests.xml`) — 173 unit and 28 functional tests,
  PHPStan level 8, TYPO3 coding standards via `.php-cs-fixer.dist.php`.
- CI: lint (PHP, XLIFF, YAML) + `composer validate --strict`, CGL dry run,
  PHPStan, unit tests on PHP 8.4 and 8.5 (8.5 non-blocking), functional
  tests against MariaDB 10.11, and a documentation render.

### Changed

- The backend module no longer builds its doc-header buttons by hand:
  `ButtonBar::makeLinkButton()` / `makeShortcutButton()` and
  `DocHeaderComponent::setMetaInformation()` are deprecated in TYPO3 v14.
  The reload button comes from the Core, the shortcut is declared with
  `setShortcutContext()`, and the module sets no page breadcrumb.
