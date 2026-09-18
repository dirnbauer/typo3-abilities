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

One typed, permissioned registry of what a TYPO3 installation can do, and one
**capability catalogue** that lists everything else the installation offers
to an AI agent — native MCP tools, agent skills, REST and webhook endpoints,
console commands — in the same shape.

Register an ability once, with a JSON Schema contract, the scopes it needs, a
risk tier and an honest list of its side effects, and it appears on every
surface at once: CLI, REST, MCP, webhooks, Fluid, the backend module and
agent skills, governed by the same execution pipeline.

----

..  card-grid::
    :columns: 1
    :columns-md: 2
    :gap: 4
    :class: pb-4
    :card-height: 100

    ..  card:: Introduction

        What the registry and the catalogue are, and the parity checklist
        against the WordPress Abilities API.

        ..  card-footer:: :ref:`Read the introduction <introduction>`
            :button-style: btn btn-secondary stretched-link

    ..  card:: Usage

        A seeded walkthrough of every surface: CLI transcript, curl, MCP tool
        names, webhook, Fluid, JavaScript, skills, scheduler.

        ..  card-footer:: :ref:`Walk through the surfaces <usage>`
            :button-style: btn btn-secondary stretched-link

    ..  card:: Configuration

        Extension settings, the policy file, scopes for backend groups,
        REST tokens and the "Run ability" webhook.

        ..  card-footer:: :ref:`Configure the registry <configuration>`
            :button-style: btn btn-secondary stretched-link

    ..  card:: Developer

        Register an ability, the execution pipeline, PSR-14 events, the
        catalogue sources and the PHP API consumers rely on.

        ..  card-footer:: :ref:`Build on the registry <developer>`
            :button-style: btn btn-secondary stretched-link

..  toctree::
    :maxdepth: 2
    :titlesonly:

    Introduction/Index
    Installation/Index
    Configuration/Index
    Usage/Index
    Developer/Index
    Changelog/Index

..  toctree::
    :hidden:

    Sitemap
