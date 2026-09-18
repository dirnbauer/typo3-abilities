# TYPO3 Abilities Registry

[![CI](https://github.com/dirnbauer/typo3-abilities/actions/workflows/ci.yml/badge.svg)](https://github.com/dirnbauer/typo3-abilities/actions/workflows/ci.yml)
[![TYPO3 14.3](https://img.shields.io/badge/TYPO3-14.3-orange.svg)](https://get.typo3.org/version/14)
[![PHP 8.4](https://img.shields.io/badge/PHP-8.4-blue.svg)](https://www.php.net/)
[![License GPL-2.0-or-later](https://img.shields.io/badge/license-GPL--2.0--or--later-green.svg)](LICENSE)

One typed, permissioned registry of what a TYPO3 installation can do, plus a capability catalogue of everything else an AI agent could use — MCP tools, skills, REST and webhook endpoints, console commands — served on every surface so an agent can discover, then use.

## What it is

An **ability** is a PHP class with one `#[AsAbility]` attribute (name, scopes, risk tier, side effects, `readOnly`/`destructive`/`idempotent` annotations) and JSON Schemas for input and output. Register the class and it appears on every surface at once — CLI, REST, MCP, webhooks, Fluid, the backend module, agent skills — through one governed pipeline:

```
Before event → policy gate → input validation → scope check → permission check → execute → output validation → After event
```

The **capability catalogue** puts the registry next to the native tools of `hn/typo3-mcp-server`, the skills of nr-llm/skillflow, EXT:reactions webhooks, sg-apicore endpoints and every console command, in one shape: id, title, description, source, surfaces, input schema, annotations and how to invoke it from each surface. It follows the WordPress Abilities API (core 6.9) vocabulary and REST layout; the manual carries the parity checklist.

## Requirements

| Requirement | Version |
|---|---|
| TYPO3 | 14.3 LTS |
| PHP | 8.4+ |
| Optional | `hn/typo3-mcp-server` (MCP), `typo3/cms-reactions` (webhooks), `typo3/cms-workspaces` (`workspace/publish`), `typo3/cms-scheduler`, `webconsulting/skillflow` or `netresearch/nr-llm` (skills), `sgalinski/sg-apicore` |

## Install

```bash
composer require webconsulting/typo3-abilities
vendor/bin/typo3 extension:setup --extension=abilities
vendor/bin/typo3 abilities:list
```

## Configure

Extension settings: `restEnabled` (1), `restBasePath` (`/abilities/v1`), `restCorsOrigins` (empty), `traceRetentionDays` (30).

Optional `config/abilities-policy.yaml` — deny rules, a risk cap and human review (example in `Resources/Private/Examples/`):

```yaml
policy:
  review_required: ["risk:high"]   # needs --approve-review or the module checkbox
  max_risk_tier: "high"            # critical abilities never run
```

Scopes for editors are granted per backend group (*Abilities* tab); admins hold `*`. REST tokens: `abilities:token:create --user=editor --scopes=content:read`.

## Use

```bash
vendor/bin/typo3 abilities:catalog --format=json                 # everything the site can do, as LLM tool context
vendor/bin/typo3 abilities:run content/search --input '{"term": "roadmap"}'
vendor/bin/typo3 abilities:run content/delete-page --input '{"uid": 42}' --approve-review

curl -H "Authorization: Bearer $TOKEN" "https://example.org/abilities/v1/abilities/content/search/run?term=roadmap"
curl -H "Authorization: Bearer $TOKEN" "https://example.org/abilities/v1/catalog?surface=mcp"
```

MCP tools are named `ability_<ns>_<name>` (`ability_abilities_catalog` first). Webhooks: the *Run ability* reaction type. Fluid: `dataProcessing.10 = Webconsulting\Abilities\DataProcessing\AbilityProcessor` for read-only abilities. Backend module: *System > Abilities* (Registry, Catalogue, Run, Traces, Tokens). JavaScript: `import { executeAbility, getCatalog } from "@webconsulting/abilities/client.js"`.

Register your own ability:

```php
#[AsAbility(name: 'news/create-article', title: 'Create news article', description: 'Creates a hidden news draft.',
    category: 'content', scopes: ['news:write'], riskTier: RiskTier::Medium, sideEffects: ['database:write'])]
final class CreateArticleAbility extends AbstractAbility
{
    public function getInputSchema(): array { /* JSON Schema */ }
    public function execute(array $input, ExecutionContext $context): mixed { /* … */ }
}
```

Eight abilities ship: `abilities/catalog`, `abilities/list`, `abilities/describe`, `system/site-info`, `content/search`, `content/create-page-draft`, `content/delete-page`, `workspace/publish`.

## Develop

```bash
composer install          # into .Build/
composer ci               # cgl + PHPStan level 8 + unit + functional (sqlite)
```

## Docs

Full manual in [`Documentation/`](Documentation/Index.rst): introduction with the WordPress parity checklist, installation, configuration (settings, scopes, tokens, policy, webhook, Fluid, scheduler), a seeded walkthrough of every surface, and the developer guide.

## License

GPL-2.0-or-later
