..  include:: /Includes.rst.txt

..  _installation:

============
Installation
============

..  _installation-requirements:

Requirements
============

..  list-table::
    :header-rows: 1
    :widths: 30 70

    *   -   Requirement
        -   Version

    *   -   TYPO3
        -   14.3 LTS

    *   -   PHP
        -   8.4 or newer

    *   -   Database
        -   Any TYPO3-supported database; the extension creates
            :sql:`tx_abilities_trace` and :sql:`tx_abilities_token` and adds
            one column to :sql:`be_groups` (and, with EXT:reactions, one to
            :sql:`sys_reaction`).

    *   -   Optional
        -   `hn/typo3-mcp-server` (MCP surface and native tools in the
            catalogue), `typo3/cms-reactions` (webhook surface),
            `typo3/cms-workspaces` (the `workspace/publish` demo),
            `typo3/cms-scheduler` (scheduled runs), `webconsulting/skillflow`
            or `netresearch/nr-llm` (skills in the catalogue),
            `sgalinski/sg-apicore` (its endpoints in the catalogue).

..  _installation-composer:

Install
=======

..  code-block:: bash

    composer require webconsulting/typo3-abilities
    vendor/bin/typo3 extension:setup --extension=abilities
    vendor/bin/typo3 abilities:list

:bash:`extension:setup` creates the tables. The last command lists the eight
abilities that ship with the extension (see :ref:`usage-demo`); everything
else the installation can do shows up in :bash:`abilities:catalog`.

..  _installation-policy:

Optional: the shipped policy
============================

Without a policy file every ability is allowed and only scopes and each
ability's own permission check govern execution. The shipped example puts
every high-risk ability behind a human review:

..  code-block:: bash

    cp vendor/webconsulting/typo3-abilities/Resources/Private/Examples/abilities-policy.yaml \
       config/abilities-policy.yaml

See :ref:`configuration-policy` for the rule grammar.
