..  include:: /Includes.rst.txt

..  _installation:

============
Installation
============

..  _installation-requirements:

Requirements
============

*   TYPO3 14.3 LTS
*   PHP 8.4 or newer
*   A database — the extension creates the :sql:`tx_abilities_trace` and
    :sql:`tx_abilities_token` tables and adds one column to
    :sql:`be_groups`.

..  _installation-composer:

Install with Composer
=====================

..  code-block:: bash

    composer require webconsulting/typo3-abilities

Then let TYPO3 create the new tables, either in
:guilabel:`Admin Tools > Maintenance > Analyze Database Structure` or on the
command line:

..  code-block:: bash

    vendor/bin/typo3 extension:setup --extension=abilities

Check that the registry is alive:

..  code-block:: bash

    vendor/bin/typo3 abilities:list

A fresh installation lists the abilities that ship with the extension —
see :ref:`demo-abilities`.

..  _installation-configuration:

Extension settings
==================

:guilabel:`Admin Tools > Settings > Extension Configuration > abilities`:

..  confval:: restEnabled
    :name: abilities-restEnabled
    :type: boolean
    :Default: 1

    Serves the REST projection below :confval:`abilities-restBasePath`.
    Switch it off to keep the registry on CLI, MCP and the backend module
    only.

..  confval:: restBasePath
    :name: abilities-restBasePath
    :type: string
    :Default: /abilities/v1

    URL prefix of the REST projection. The middleware answers below this
    path before site resolution, so the API needs no site, page or
    TypoScript.

..  confval:: restCorsOrigins
    :name: abilities-restCorsOrigins
    :type: string
    :Default: (empty)

    Comma-separated list of browser origins allowed to call the API, or
    `*` for any origin. Empty means no CORS headers are sent at all, which
    is the right setting for same-origin and non-browser clients.

..  confval:: traceRetentionDays
    :name: abilities-traceRetentionDays
    :type: int
    :Default: 30

    Execution traces older than this are pruned opportunistically on a small
    fraction of writes, so no scheduler task is needed. `0` keeps traces
    forever.

..  _installation-policy:

Optional: a site policy
=======================

Without a policy file every ability is allowed, and only scopes and the
ability's own permission check govern execution. To require human review for
risky abilities, copy the shipped example to the project root:

..  code-block:: bash

    cp vendor/webconsulting/typo3-abilities/Resources/Private/Examples/abilities-policy.yaml \
       config/abilities-policy.yaml

See :ref:`policy` for the rule grammar.

..  _installation-permissions:

Optional: scopes for editors
============================

Administrators hold every scope. To let non-admin editors run abilities,
edit their backend group and pick the scopes in the :guilabel:`Abilities`
tab — see :ref:`permissions`.
