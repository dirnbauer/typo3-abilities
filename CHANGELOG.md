# Changelog

All notable changes to `webconsulting/typo3-abilities` are documented here.

## 1.0.0 — 2026-09-12

First stable release: registry, categories, annotations, events, permissions,
tokens, REST and a protocol-neutral MCP projection.

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
- Backend: AJAX routes `abilities_list`, `abilities_categories`,
  `abilities_tokens`; ES module `@webconsulting/abilities/client.js`.
- Extension settings `restEnabled`, `restBasePath`, `restCorsOrigins`,
  `traceRetentionDays` (traces are pruned opportunistically).
- Test suites: unit (`Build/phpunit/UnitTests.xml`) and functional on sqlite
  (`Build/phpunit/FunctionalTests.xml`), PHPStan level 8.
