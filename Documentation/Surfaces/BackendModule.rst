..  include:: /Includes.rst.txt

..  _surfaces-backend-module:

==============
Backend module
==============

:guilabel:`System > Abilities` is the registry seen from inside TYPO3. It
needs no token and no second login: the logged-in backend user is the
identity, and runs execute on the `backend` surface with the scopes that
user's groups grant (administrators hold `*`).

The module is registered for administrators. It deliberately lists the
**whole** registry regardless of each ability's :confval:`asability-expose`
surfaces — an administrator inspecting the site should see every capability,
including the ones hidden from agents. Execution stays governed: policy,
scopes and :php:`checkPermission()` gate every run.

..  _surfaces-backend-module-registry:

Registry tab
============

The full registry as a table: name and title, category, annotations
(risk tier, read-only, destructive, idempotent), required scopes, side
effects, the surfaces it is projected on and the derived REST method and MCP
tool name.

Four filters narrow it down without a page reload: free text (name, title,
description), category, surface and risk tier. :guilabel:`Run` on a row
opens that ability in the Run tab.

..  _surfaces-backend-module-run:

Run tab
=======

Pick an ability and the module builds a form **from its input schema**:

..  list-table::
    :header-rows: 1
    :widths: 35 65

    *   -   Schema
        -   Form control

    *   -   `enum`
        -   select

    *   -   `boolean`
        -   checkbox

    *   -   `integer`, `number`
        -   number input, with `minimum` and `maximum` as bounds

    *   -   `string`
        -   text input, with `minLength`, `maxLength` and `pattern`

    *   -   `object`, `array`, union types
        -   a JSON textarea — a generic form cannot honestly represent them

Declared defaults pre-fill the fields, `description` becomes the help text
below each one, required fields are marked, and empty optional fields are
omitted from the request so the ability's own defaults apply.

When the site policy requires a review for this ability, a
:guilabel:`Approve review` checkbox appears — with the policy's own reason
next to it — and ticking it is the human approval. Where the policy denies
an ability outright, the Execute button is disabled and says why. Running a
**destructive** ability additionally asks for confirmation in a modal.

The result panel shows the verbatim result envelope, the HTTP status, the
duration and the **uid of the trace row** the run produced, so the run can
be found again in the Traces tab.

..  _surfaces-backend-module-traces:

Traces tab
==========

The newest :sql:`tx_abilities_trace` rows — every attempt from every surface,
successful or denied — with the ability, surface, outcome (or error code and
message), duration, acting backend user and the requested input.

Filter by ability, by surface and by outcome. The surface filter offers
exactly the surfaces that actually occur in the log.

..  _surfaces-backend-module-tokens:

Tokens tab
==========

Manage the REST bearer tokens of the acting backend user: list them with
their scopes, expiry and last use, create a new one, and revoke one
(with a confirmation — it stops working immediately for every client).

The scope picker offers exactly the scopes the registry declares, so a token
cannot be given a scope no ability uses. The created token's **plaintext is
shown exactly once**, in a warning box: only its SHA-256 hash is stored and
it can never be retrieved again.

..  seealso::
    :ref:`permissions-tokens` explains the intersection rule that keeps a
    token from exceeding its user's own rights.
