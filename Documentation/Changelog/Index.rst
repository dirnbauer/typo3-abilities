..  include:: /Includes.rst.txt

..  _changelog:

=========
Changelog
=========

The authoritative, complete changelog of this extension lives in
:file:`CHANGELOG.md` in the repository root and is maintained in the
`Keep a Changelog <https://keepachangelog.com/en/1.1.0/>`__ format:

*   `CHANGELOG.md on GitHub <https://github.com/dirnbauer/typo3-abilities/blob/main/CHANGELOG.md>`__

..  _changelog-1-0-0:

1.0.0
=====

First stable release: the registry, categories, annotations, PSR-14 events,
scopes and backend-group permissions, REST tokens, the REST projection, a
protocol-neutral MCP projection, the skills contract, the four-tab backend
module and four demo abilities.

..  seealso::
    *   :ref:`demo-abilities` for what ships in the registry.
    *   `The 1.0.0 entry in CHANGELOG.md
        <https://github.com/dirnbauer/typo3-abilities/blob/main/CHANGELOG.md>`__
        for the full list, including the breaking changes against the
        pre-release code.

..  _changelog-versioning:

Versioning
==========

This extension follows `Semantic Versioning <https://semver.org/>`__. The
public API is the :php:`#[AsAbility]` attribute, the interfaces and domain
objects in :php:`Registry\`, :php:`Domain\` and :php:`Event\`, the CLI
command signatures, the REST paths and response envelopes, and the
`@webconsulting/abilities/client.js` exports.
