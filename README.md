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

## Projections

**MCP** — when [hn/typo3-mcp-server](https://github.com/dirnbauer/typo3-mcp-server) is installed, a compiler pass generates one `mcp.tool`-tagged service per ability exposed to `mcp`. `news/create-article` appears as the MCP tool `ability_news_create-article`, with `readOnlyHint` / `destructiveHint` / `idempotentHint` / `openWorldHint` annotations derived from the registry metadata. The MCP tool list is *generated from* the registry at container compile time.

**CLI** —

```bash
vendor/bin/typo3 abilities:list                # the registry, human-readable (or --json)
vendor/bin/typo3 abilities:describe news/create-article   # full contract as JSON
vendor/bin/typo3 abilities:run news/create-article --input '{"title": "Hello"}'
vendor/bin/typo3 abilities:run risky/thing --approve-review   # HITL flag for review-gated abilities
```

**REST** — planned: discovery + run endpoints in the spirit of WordPress's `wp-abilities/v1`, delegated to the sg_apicore token/scope infrastructure. The `ExecutionContext` already carries explicit scope grants for exactly this surface.

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

- **Trusted surfaces** (CLI as `_cli_`, MCP behind the MCP server's own OAuth + capability-manifest enforcement) run with `grantedScopes: null` — scope checks pass, policy and `checkPermission()` still apply.
- **Token surfaces** (REST, future) pass explicit scope grants; every scope an ability declares must be granted.
- `checkPermission()` is the place for TYPO3-native checks (backend user, table permissions, workspace) — it runs on every surface.

## Development

```bash
composer install
composer test      # PHPUnit
composer phpstan   # level max
```

## Status & roadmap

Alpha — the registry core, execution pipeline, policy gate, CLI projection and MCP projection are implemented and tested. Next, in order:

1. REST projection (discovery + run) on sg_apicore tokens/scopes
2. Execution trace records (feeds the unified `agent_run` trace store — strategy item 13)
3. Consent/policy records in TCA instead of YAML-only (item 15)
4. Proposal to the TYPO3 AI initiative (item 23)

Part of the [TYPO3 agentic strategy](https://github.com/dirnbauer) — item 19: *a capability registry, not hand-rolled endpoints*.
