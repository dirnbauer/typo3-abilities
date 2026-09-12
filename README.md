# TYPO3 Abilities Registry

One typed, permissioned registry of what a TYPO3 installation can do. CLI commands, REST routes, MCP tools, the backend module and agent skills are **projections** of that registry — never hand-rolled endpoints.

An ability declares in one attribute what it is, which scopes it needs, how risky it is and which subsystems it touches; its input and output are JSON Schemas. Add the class, and it appears on every surface at once, governed by one execution pipeline:

```
policy gate → input validation → scope check → permission check → execute → output validation
```

WordPress proved the architecture with the **Abilities API** (core 6.9) and its MCP Adapter. This is that bet for TYPO3, with the governance layer WordPress does not have: a site policy, human-in-the-loop review, risk tiers and execution traces.

## Requirements

- TYPO3 14.3 LTS
- PHP 8.4+

## Install

```bash
composer require webconsulting/typo3-abilities
vendor/bin/typo3 extension:setup --extension=abilities
vendor/bin/typo3 abilities:list
```

## Configure

Extension settings (*Admin Tools > Settings > Extension Configuration*):

| Setting | Default | Purpose |
|---|---|---|
| `restEnabled` | `1` | Serve the REST projection |
| `restBasePath` | `/abilities/v1` | URL prefix, mounted before site resolution |
| `restCorsOrigins` | *(empty)* | Comma-separated browser origins, `*` for any |
| `traceRetentionDays` | `30` | Trace retention; `0` keeps them forever |

Optional `config/abilities-policy.yaml` — deny rules, a risk cap and human review (copy [`Resources/Private/Examples/abilities-policy.yaml`](Resources/Private/Examples/abilities-policy.yaml)):

```yaml
policy:
  name: "Production"
  deny:
    - "side-effect:network:outbound"
  review_required:
    - "risk:high"          # needs --approve-review or the module checkbox
  max_risk_tier: "high"    # critical abilities never run
```

Scopes for non-admin editors are granted per backend user group (*Abilities* tab); administrators hold `*`.

## Use

Register an ability — that is the whole registration, the DI container collects it:

```php
#[AsAbility(
    name: 'news/create-article',
    title: 'Create news article',
    description: 'Creates a news article as a hidden draft.',
    category: 'content',
    scopes: ['news:write'],
    riskTier: RiskTier::Medium,
    sideEffects: ['database:write'],
)]
final class CreateArticleAbility extends AbstractAbility
{
    public function getInputSchema(): array { /* JSON Schema */ }
    public function execute(array $input, ExecutionContext $context): mixed { /* … */ }
}
```

**CLI**

```bash
vendor/bin/typo3 abilities:run content/search --input '{"term": "roadmap", "limit": 5}'
```

**REST** — the method follows the annotations: read-only `GET`, destructive `DELETE`, otherwise `POST`.

```bash
curl -H "Authorization: Bearer $TOKEN" \
  "https://example.org/abilities/v1/abilities/content/search/run?term=roadmap&limit=5"
```

**MCP** — `Projection\Mcp\McpProjection` emits protocol-neutral tool descriptors (`content/search` → `ability_content_search`) with honest `readOnlyHint` / `destructiveHint` / `idempotentHint` annotations; an MCP server extension bridges them. No MCP SDK is required here.

**Skills** — a skill declares the abilities it needs; `Skills\SkillAbilityContract` resolves them to client tool names and validates them against the registry and the policy:

```yaml
---
name: publish-editorial-drafts
description: Reviews pending drafts with a human and publishes them once approved.
abilities:
  - workspace/publish
  - content/search
---
```

**Backend module** — *System > Abilities*: browse and filter the registry, run any ability from a form generated out of its input schema, read the execution traces, manage REST tokens.

Seven abilities ship: `system/site-info`, `abilities/list`, `abilities/describe`, and four demos — `content/search`, `content/create-page-draft`, `content/delete-page` and `workspace/publish`.

## Develop

```bash
composer install
composer test        # unit + functional (sqlite)
composer phpstan     # level 8
vendor/bin/php-cs-fixer fix --dry-run --diff
docker run --rm -v $PWD:/project ghcr.io/typo3-documentation/render-guides:latest --config=Documentation
```

## Docs

Full manual in [`Documentation/`](Documentation/Index.rst): concepts, registering abilities, all six surfaces, permissions, policy, events, the demo abilities, and a comparison table against the WordPress Abilities API.

## License

GPL-2.0-or-later
