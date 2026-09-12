# Changelog

All notable changes to `webconsulting/typo3-abilities` are documented here.
This project follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/)
and [Semantic Versioning](https://semver.org/).

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
