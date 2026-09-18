..  include:: /Includes.rst.txt

..  _changelog:

=========
Changelog
=========

The complete changelog lives in :file:`CHANGELOG.md` in the repository root
(`Keep a Changelog <https://keepachangelog.com/en/1.1.0/>`__ format):
`CHANGELOG.md on GitHub <https://github.com/dirnbauer/typo3-abilities/blob/main/CHANGELOG.md>`__.

..  _changelog-1-1-0:

1.1.0
=====

The capability catalogue (abilities, MCP tools, skills, REST/webhook
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
