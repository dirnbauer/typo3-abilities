..  include:: /Includes.rst.txt

..  _start:

========================
TYPO3 Abilities Registry
========================

:Extension key:
    abilities

:Package name:
    webconsulting/typo3-abilities

:Version:
    |release|

:Language:
    en

:Author:
    Kurt Dirnbauer, webconsulting business services gmbh

:License:
    This document is published under the
    `Creative Commons BY 4.0 <https://creativecommons.org/licenses/by/4.0/>`__
    license.

:Rendered:
    |today|

----

One typed, permissioned registry of what a TYPO3 installation can do. CLI
commands, REST routes, MCP tools, the backend module and agent skills are
**projections** of that registry — never hand-rolled endpoints.

Register an ability once, with a JSON-Schema contract, the scopes it needs,
a risk tier and an honest list of its side effects, and it appears on every
surface at once, governed by the same execution pipeline.

----

..  card-grid::
    :columns: 1
    :columns-md: 2
    :gap: 4
    :class: pb-4
    :card-height: 100

    ..  card:: Introduction

        What the registry is, and how it compares to the WordPress
        Abilities API it takes its vocabulary from.

        ..  card-footer:: :ref:`Read the introduction <introduction>`
            :button-style: btn btn-secondary stretched-link

    ..  card:: Registering abilities

        The :php:`#[AsAbility]` attribute, input and output schemas,
        permission checks and categories.

        ..  card-footer:: :ref:`Register an ability <registering-abilities>`
            :button-style: btn btn-secondary stretched-link

    ..  card:: Surfaces

        CLI, REST, MCP, the backend module, the JavaScript client and
        agent skills — one registry, many projections.

        ..  card-footer:: :ref:`Browse the surfaces <surfaces>`
            :button-style: btn btn-secondary stretched-link

    ..  card:: Governance

        Scopes and backend groups, the policy file, human review and the
        execution traces.

        ..  card-footer:: :ref:`Understand the policy <policy>`
            :button-style: btn btn-secondary stretched-link

..  toctree::
    :maxdepth: 2
    :titlesonly:

    Introduction/Index
    Installation/Index
    Concepts/Index
    RegisteringAbilities/Index
    Surfaces/Index
    Permissions/Index
    Policy/Index
    Events/Index
    DemoAbilities/Index
    Changelog/Index

..  toctree::
    :hidden:

    Sitemap
