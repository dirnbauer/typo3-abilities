..  include:: /Includes.rst.txt

..  _permissions:

===========
Permissions
===========

Three layers decide whether a run happens, and all three must agree:

#.  **Scopes** — may this caller use this kind of ability at all?
#.  **TYPO3 permissions** — may this backend user touch this record, page or
    workspace? Checked by the ability itself and by the DataHandler.
#.  **The site policy** — does this installation allow this ability to run
    unattended? See :ref:`policy`.

..  _permissions-scopes:

Scopes
======

A scope is a :code:`resource:operation` string — `content:read`,
`pages:write`, `workspace:publish`. An ability declares the scopes it needs
in :confval:`asability-scopes`; every one of them must be granted or the run
fails with `ability_invalid_permissions` naming the missing scopes.

Two wildcards exist:

*   :code:`*` grants every scope.
*   :code:`news:*` grants every scope of the `news` resource.

A **trusted** surface (CLI without :bash:`--as-user`, MCP) carries no scope
list at all and skips the check — the host has already authenticated the
actor. Policy and :php:`checkPermission()` still apply everywhere.

..  _permissions-groups:

Scopes for backend users
========================

Scopes are granted through backend user groups. Each group has an
:guilabel:`Abilities` tab with a :guilabel:`Ability scopes` field; a user's
scopes are the union of all their groups', subgroups included.

The picker offers exactly the scopes the live registry declares, plus
:guilabel:`All scopes (*)` — so nobody has to guess a scope string.

..  note::
    Administrators always hold :code:`*`. The scope field only matters for
    non-admin editors — and for tokens, whose scopes are intersected with
    their user's.

..  _permissions-tokens:

REST tokens
===========

A token is an opaque bearer credential bound to one backend user:

*   Generated once, stored only as a SHA-256 hash — the plaintext is shown
    exactly once and cannot be recovered.
*   Carries its own scope list, optionally expires, can be revoked.
*   Its **effective** scopes are :code:`token scopes ∩ user scopes`.

That intersection is the important part: issuing a token with
:code:`pages:write` to a user whose groups do not grant `pages:write` gives
a token that cannot write pages. A leaked token can never do more than the
person it belongs to, and demoting the user immediately demotes every token
they hold.

Create tokens on the CLI (:ref:`surfaces-cli-tokens`) or in the backend
module (:ref:`surfaces-backend-module-tokens`).

..  _permissions-check:

The ability's own check
=======================

:php:`checkPermission()` runs on **every** surface, trusted or not. It is
where TYPO3-native checks belong — backend user, table and page
permissions, workspace access:

..  code-block:: php

    public function checkPermission(array $input, ExecutionContext $context): bool|string
    {
        $user = $GLOBALS['BE_USER'] ?? null;
        if (!$user instanceof BackendUserAuthentication) {
            return 'This ability needs an authenticated backend user.';
        }

        return $user->isAdmin() || $user->doesUserHaveAccess($page, Permission::PAGE_NEW)
            ? true
            : sprintf('Backend user may not create pages below page #%d.', $parent);
    }

Returning the reason as a string is worth the extra line: it is what the
caller — often an agent — needs in order to do something else instead of
retrying blindly.

..  _permissions-writing:

Writing records safely
======================

Abilities that change data should go through the **DataHandler** with the
acting backend user, as the demo abilities do. That single decision buys
page permissions, workspace versioning, slug generation, reference index
updates and the history log — all the things a raw SQL write silently
skips.
