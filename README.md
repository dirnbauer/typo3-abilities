# TYPO3 Abilities Registry

One typed, permissioned registry of what a TYPO3 installation can do. MCP tools, CLI commands and REST routes become **projections** of this registry — never hand-rolled endpoints.

WordPress proved the architecture with the **Abilities API** (core 6.9) and its official **MCP Adapter**: register capabilities once — with contracts, permissions and annotations — and project them onto whatever protocol the agentic web speaks this year. This extension is that architectural bet for TYPO3, wired into the governance vocabulary this ecosystem already uses ([typo3-capability-manifest](https://github.com/dirnbauer/typo3-capability-manifest) side-effect subsystems and policy semantics, [sg_apicore](https://github.com/dirnbauer/sg_apicore) `resource:operation` scopes, [typo3-mcp-server](https://github.com/dirnbauer/typo3-mcp-server) tool projection).

## The registry schema

Every ability declares, via the `#[AsAbility]` attribute:

| Field | Meaning |
|---|---|
| `name` | Unique `namespace/ability-name` (lowercase kebab-case) |
| `title`, `description`, `category` | Presentation for humans and agents |
| **contract** | `getInputSchema()` / `getOutputSchema()` — JSON Schema, may be computed at runtime |
| `scopes` | Required token scopes, `resource:operation` convention (e.g. `news:write`) |
| `riskTier` | `low` / `medium` / `high` / `critical` — same vocabulary and scores as capability manifests |
| `sideEffects` | Capability-manifest subsystem vocabulary (`database:write`, `network:outbound`, `mail:send`, …); empty = read-only |
| `idempotent`, `destructive` | Truthful execution hints, projected onto MCP tool annotations |
| `expose` | Which surfaces may project this ability: `mcp`, `cli`, `rest` |

## Registering an ability

```php
use Webconsulting\Abilities\Attribute\AsAbility;
use Webconsulting\Abilities\Domain\ExecutionContext;
use Webconsulting\Abilities\Domain\RiskTier;
use Webconsulting\Abilities\Registry\AbstractAbility;

#[AsAbility(
    name: 'news/create-article',
    title: 'Create news article',
    description: 'Creates a news article as a workspace draft.',
    category: 'content',
    scopes: ['news:write'],
    riskTier: RiskTier::Medium,
    sideEffects: ['database:write'],
)]
final class CreateArticleAbility extends AbstractAbility
{
    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'required' => ['title'],
            'properties' => [
                'title' => ['type' => 'string', 'minLength' => 3],
                'bodytext' => ['type' => 'string', 'default' => ''],
            ],
        ];
    }

    public function getOutputSchema(): array
    {
        return ['type' => 'object', 'required' => ['uid'], 'properties' => ['uid' => ['type' => 'integer']]];
    }

    public function execute(array $input, ExecutionContext $context): mixed
    {
        // … create the record (workspace-aware) …
        return ['uid' => $uid];
    }
}
```

That's the whole registration: the class implements `AbilityInterface` (auto-tagged `abilities.ability`), the DI container collects it into the `AbilitiesRegistry`, and every projection picks it up from there.

## Execution pipeline

Every surface goes through the same governed pipeline (`AbilityExecutor`):

```
policy gate → input validation → scope check → permission check → execute → output validation
```

- Mirrors the WordPress Abilities API order (validate → permission → execute → validate), with a **policy gate** in front because governance outranks contracts.
- Results are a stable envelope: `{ok, data}` or `{ok: false, errorCode, error}` with machine-readable codes (`policy_denied`, `invalid_input`, `permission_denied`, `execution_error`, `invalid_output`).
- The schema validator is dependency-free (a documented JSON Schema subset) so the extension stays clean enough to propose upstream.

## Discovery — “what can this site do?”

Because every ability is a typed record in one registry, an external system can **ask the site what it can do and get a structured answer** — a list of abilities, each with a JSON-Schema input/output contract, its risk tier and its required scopes. Nothing is hardcoded on either side.

```http
GET /api/abilities/v1/abilities            → { "abilities": [ … ], "total": 18 }
GET /api/abilities/v1/abilities/news/create-article
                                           → the full contract + input/output JSON Schemas
POST /api/abilities/v1/abilities/news/create-article/run
                                           → runs it, returns the {ok,data} envelope
```

That single fact — *the registry is self-describing* — is what lets automation reach TYPO3 without a bespoke integration per action:

- **AI agents / MCP clients** discover abilities through `tools/list` (the MCP projection below); the JSON schemas tell the model exactly how to call each one.
- **Automation platforms (n8n, Zapier, Make, …)** point a generic HTTP node (or an MCP node) at the discovery endpoint, read the returned schemas, and build the request dynamically — the same node drives *any* ability, present or future, instead of a hand-written connector per operation.
- **The agent-readable web** — [`webconsulting/typo3-llms-txt`](https://github.com/dirnbauer/typo3-llms-txt) advertises the live registry in each site's `agents.md`, so a crawler learns the site's capabilities before it makes a single call.

Add a new `#[AsAbility]` class and it shows up in all of these at once — no endpoint, no client change, no redeploy of the integration.

## Projections

One registry, many protocol projections — a projection never re-implements an ability, it just exposes the registry over one more surface.

**MCP** — when [hn/typo3-mcp-server](https://github.com/dirnbauer/typo3-mcp-server) is installed, a compiler pass generates one `mcp.tool`-tagged service per ability exposed to `mcp`. `news/create-article` appears as the MCP tool `ability_news_create-article`, with `readOnlyHint` / `destructiveHint` / `idempotentHint` / `openWorldHint` annotations derived from the registry metadata. The MCP tool list is *generated from* the registry at container compile time.

**REST** — the discovery + run endpoints shown above, served by the [sg_apicore](https://github.com/dirnbauer/sg_apicore) fork behind backend-user-bound opaque tokens (scope-checked per ability). This is the surface HTTP automation and the web client (`examples/abilities-studio.html`) talk to.

**CLI** —

```bash
vendor/bin/typo3 abilities:list                # the registry, human-readable (or --json)
vendor/bin/typo3 abilities:describe news/create-article   # full contract as JSON
vendor/bin/typo3 abilities:run news/create-article --input '{"title": "Hello"}'
vendor/bin/typo3 abilities:run risky/thing --approve-review   # HITL flag for review-gated abilities
```

**Backend module** — *System → Abilities* browses the registry and runs any ability as the logged-in backend user, through the same governed pipeline, with no token or extra login. Read-only listing plus a per-ability runner (and a review-approval checkbox for high-risk abilities).

**Desktop / custom hosts** — any extension can host its own projection: the [desktop connector](https://github.com/kdirnbauer/typo3-desktop-connector) fronts its news/pages/content/workspace abilities over its own JWT API for the Electron editor. A projection just calls `AbilityExecutor::execute()` with an `ExecutionContext` for its surface.

## Site-wide policy

Optional `config/abilities-policy.yaml` in the project root (same location and semantics family as capability-manifest's `capability-policy.yaml`):

```yaml
policy:
  name: "Production abilities policy"
  deny:
    - "side-effect:network:outbound"   # no ability may call out
    - "experimental/*"                 # namespace-wide block
  review_required:
    - "risk:high"                      # HITL approval required
    - "scope:workspace:publish"
  max_risk_tier: "high"                # critical abilities never run
```

Rule grammar: exact name, `namespace/*`, `*`, `risk:<tier>`, `scope:<scope>` (prefix), `side-effect:<subsystem>` (prefix). `deny` always wins; `review_required` blocks unless the execution context carries an explicit human approval (`--approve-review` on CLI). No policy file means allow-all — scopes and the ability's own permission check still always run.

## Trust model

- **Trusted surfaces** run with `grantedScopes: null` — scope checks are skipped because the host already authenticated the actor; policy and `checkPermission()` still apply. This covers the CLI (`_cli_`), MCP (behind the MCP server's OAuth), the **backend module** (the logged-in backend user) and the **desktop connector** (behind its JWT).
- **Scoped surfaces** pass explicit grants — REST carries the token's scopes, and every scope an ability declares must be present or the run is denied.
- `checkPermission()` is the place for TYPO3-native checks (backend user, table permissions, workspace) — it runs on *every* surface, trusted or scoped.

## Development

```bash
composer install
composer test      # PHPUnit
composer phpstan   # level max
```

## Status & roadmap

Alpha, but broad — the registry core, execution pipeline, policy gate, execution traces (`tx_abilities_trace`) and **five projections** (CLI, MCP, REST, backend module, desktop) are implemented, tested and verified live. Next, in order:

1. Consent/policy records in TCA instead of YAML-only (strategy item 15)
2. Cross-check audit: an ability's `sideEffects` ⊆ its host extension's capability manifest
3. Migrate the generic MCP-server tools into abilities so the registry is the single source of truth for them too (leaving the MCP server as pure transport)
4. TER release + proposal to the TYPO3 AI initiative (item 23)

Part of the [TYPO3 agentic strategy](https://github.com/dirnbauer) — item 19: *a capability registry, not hand-rolled endpoints*.
