..  include:: /Includes.rst.txt

..  _surfaces-rest:

====
REST
====

The REST projection is served by a PSR-15 middleware mounted before site
resolution, so it needs no site, page or TypoScript. Base path and CORS come
from the extension settings (:ref:`installation-configuration`).

..  _surfaces-rest-authentication:

Authentication
==============

Two identities are accepted.

**A bearer token** — created with :bash:`abilities:token:create` or in the
backend module's :guilabel:`Tokens` tab:

..  code-block:: bash

    curl -H "Authorization: Bearer abl_…" https://example.org/abilities/v1/abilities

The token is bound to a backend user. That user is booted for the request —
group data, workspace, language — so abilities run with real TYPO3
permissions. The effective scopes are the **intersection** of the token's
scopes and the user's scopes: a token can never widen its user's rights.

**A backend session** — for same-origin clients such as the backend module
or a browser extension. Non-GET requests must additionally send
:code:`X-Requested-With` as a CSRF guard.

An unauthenticated request is answered with
:code:`401 rest_unauthorized` and a :code:`WWW-Authenticate` header.

..  _surfaces-rest-workspace:

Working in a workspace
----------------------

Send :code:`X-TYPO3-Workspace: 3` to run inside that workspace. Abilities
that write records then create workspace versions instead of touching live.

..  _surfaces-rest-endpoints:

Endpoints
=========

..  list-table::
    :header-rows: 1
    :widths: 12 46 42

    *   -   Method
        -   Path
        -   Purpose

    *   -   GET
        -   :code:`{base}/abilities`
        -   List the abilities exposed to REST. Query parameters:
            `category`, `page`, `per_page` (max 100). Responds with the
            `X-Total` and `X-Total-Pages` headers.

    *   -   GET
        -   :code:`{base}/abilities/{namespace}/{name}`
        -   The full contract including both JSON Schemas.

    *   -   GET / POST / DELETE
        -   :code:`{base}/abilities/{namespace}/{name}/run`
        -   Run it. The method is fixed per ability — see below.

    *   -   GET
        -   :code:`{base}/categories`
        -   All categories, each flagged whether an ability uses it.

    *   -   GET
        -   :code:`{base}/categories/{slug}`
        -   One category with the names of its abilities.

..  _surfaces-rest-method:

The method is the annotation
============================

As in the WordPress Abilities REST API, the HTTP method of a run is derived
from the ability's annotations, not chosen by the caller:

*   **read-only** (no side effects) → :code:`GET`
*   **destructive** → :code:`DELETE`
*   everything else → :code:`POST`

Using the wrong method answers :code:`405` with an :code:`Allow` header and
the error code `rest_ability_invalid_method`. The method is therefore a
contract, not a convention: a client cannot accidentally call a destructive
ability as if it were a harmless read.

..  _surfaces-rest-input:

Passing input
=============

For :code:`GET` and :code:`DELETE`, input comes from query parameters mapped
onto the top-level properties of the input schema, coerced to the declared
types (everything arrives as a string):

..  code-block:: bash

    curl -H "Authorization: Bearer $TOKEN" \
      "https://example.org/abilities/v1/abilities/content/search/run?term=roadmap&limit=5&tables=pages"

Alternatively pass the whole object as JSON in an :code:`input` parameter:
:code:`?input={"term":"roadmap"}`.

For :code:`POST`, send a JSON body — either wrapped or bare:

..  code-block:: bash

    curl -X POST -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json" \
      -d '{"input": {"parent": 2, "title": "Press release"}}' \
      https://example.org/abilities/v1/abilities/content/create-page-draft/run

..  _surfaces-rest-responses:

Responses
=========

..  code-block:: json
    :caption: Success

    {"ok": true, "data": {"uid": 42, "slug": "/about/press-release"}}

..  code-block:: json
    :caption: Failure

    {"ok": false, "code": "ability_invalid_permissions", "error": "Ability \"content/create-page-draft\" requires scopes not granted to this context: pages:write."}

Every response is :code:`Cache-Control: no-store` and
:code:`X-Content-Type-Options: nosniff`. HTTP status codes follow
:ref:`concepts-error-codes`.

..  _surfaces-rest-review:

Review-gated abilities over REST
================================

A bearer token is not a human in the loop. An ability the policy has put
behind `review_required` therefore always answers:

..  code-block:: json

    {"ok": false, "code": "ability_review_required", "error": "Ability \"content/delete-page\" requires human review per rule \"risk:high\" of policy \"Default abilities policy\" and the execution context carries no approval."}

with status :code:`409`. The run must be approved on the CLI
(:bash:`--approve-review`) or in the backend module. This is by design: it
is what keeps an automated client from deleting things unattended.

..  _surfaces-rest-discovery:

Discovery for generic clients
=============================

Because the listing carries every ability's schemas, a generic HTTP node in
n8n, Make or Zapier can read the registry and build the request itself —
one node driving *any* ability, present or future, instead of a hand-written
connector per operation.
