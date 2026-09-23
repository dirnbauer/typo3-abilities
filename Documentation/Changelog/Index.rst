..  include:: /Includes.rst.txt

..  _changelog:

=========
Changelog
=========

The complete changelog lives in :file:`CHANGELOG.md` in the repository root
(`Keep a Changelog <https://keepachangelog.com/en/1.1.0/>`__ format):
`CHANGELOG.md on GitHub <https://github.com/dirnbauer/typo3-abilities/blob/main/CHANGELOG.md>`__.

..  _changelog-1-3-0:

1.3.0
=====

Maintenance release without a public API change: the backend module is fully
translated (English and German, including the JavaScript through the v14
`~labels/abilities.mod` module), the catalogue folds its invocations, the
module stylesheet only uses v14 custom properties, PHP 8.4 idioms throughout,
requirements aligned with TYPO3 14.3 and CI on PHP 8.4 and 8.5.

..  _changelog-1-2-0:

1.2.0
=====

Terminology fix inside the catalogue layer, no public surface change:
:php:`CapabilityCatalog` → :php:`Catalog\AbilityCatalog`,
:php:`CapabilityEntry` → :php:`Catalog\CatalogEntry`,
:php:`CapabilitySourceInterface` → :php:`Catalog\CatalogSourceInterface`
(:php:`getCapabilities()` → :php:`getEntries()`), DI tag
`abilities.capability_source` → `abilities.catalog_source`. The old names and
the old tag still work and are removed in 2.0.0. "Capability" is now reserved
for the MCP capability manifest's permission gating — see
:ref:`developer-vocabulary`.

..  _changelog-1-1-0:

1.1.0
=====

The ability catalogue (abilities, MCP tools, skills, REST/webhook
endpoints, console commands) on every surface — :bash:`abilities:catalog`,
:code:`GET /abilities/v1/catalog`, `ability_abilities_catalog`, the module's
Catalogue tab; two new surfaces (EXT:reactions "Run ability" webhook, the
Fluid :php:`AbilityProcessor`); the announced removal of the deprecated
:php:`AbilityExecutedEvent`; internal simplifications (one
:php:`describe()` contract, :php:`RestEndpoint` enum, the REST identity is
the :php:`ExecutionContext`); the toolchain moved to :file:`.Build/`.

..  _changelog-1-0-0:

1.0.0
=====

First stable release: registry, categories, annotations, PSR-14 events,
scopes and backend-group permissions, REST tokens, the REST projection, the
protocol-neutral MCP projection, the skills contract, the backend module and
four demo abilities.
