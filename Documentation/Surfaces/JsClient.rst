..  include:: /Includes.rst.txt

..  _surfaces-js-client:

=================
JavaScript client
=================

The ES module `@webconsulting/abilities/client.js` is the browser-side
counterpart of the WordPress `@wordpress/abilities` package: it reads and
runs server abilities, and it can register abilities that live in the
browser only.

It talks to session-guarded backend AJAX routes, so it works inside the
TYPO3 backend without a token — the logged-in backend user is the identity.

..  _surfaces-js-client-reading:

Reading and running server abilities
====================================

..  code-block:: js
    :caption: EXT:my_extension/Resources/Public/JavaScript/example.js

    import { getAbilities, getAbility, executeAbility } from "@webconsulting/abilities/client.js";

    const { abilities, total } = await getAbilities({ category: "content" });

    const contract = await getAbility("content/search");
    console.log(contract.inputSchema, contract.policy.reviewRequired);

    const result = await executeAbility("content/search", { term: "roadmap", limit: 5 });
    if (result.ok) {
        console.log(result.data.results);
    } else {
        console.warn(result.errorCode, result.error);
    }

:js:`executeAbility()` always resolves to the result envelope plus the HTTP
`status`; it does **not** throw on a governed denial, because a denial is a
normal outcome and the code should be able to read it.

For an ability behind a review rule, pass the human's approval explicitly:

..  code-block:: js

    await executeAbility("content/delete-page", { uid: 42 }, { approveReview: true });

..  _surfaces-js-client-tokens-traces:

Tokens and traces
=================

..  code-block:: js

    import { getTokens, createToken, revokeToken, getTraces } from "@webconsulting/abilities/client.js";

    const { tokens, scopes } = await getTokens();

    // The plaintext exists exactly once — show it, never store it.
    const issued = await createToken({ name: "n8n", scopes: ["content:read"], expiresInDays: 90 });
    console.log(issued.token, issued.effectiveScopes);

    await revokeToken(issued.uid);

    const { traces } = await getTraces({ ability: "content/search", ok: "0", limit: 20 });

..  _surfaces-js-client-client-side:

Client-side abilities
=====================

An ability can also live purely in the browser — for example something that
manipulates the current form or the editor selection, where a round trip to
the server makes no sense:

..  code-block:: js

    import { registerAbility, getAbilities } from "@webconsulting/abilities/client.js";

    registerAbility({
        name: "editor/selected-text",
        title: "Selected text",
        description: "Returns the text the editor currently has selected.",
        category: "content",
        outputSchema: { type: "object", properties: { text: { type: "string" } } },
        readOnly: true,
        execute: () => ({ text: String(window.getSelection() ?? "") }),
    });

    // Server abilities and client abilities in one list:
    const { abilities } = await getAbilities();

Client abilities follow the same shape as :php:`#[AsAbility]` — name,
title, description, category, schemas, `readOnly` / `destructive` /
`idempotent` / `instructions` — and the same name pattern is enforced. They
are visible to :js:`getAbilities()` and :js:`getAbility()`, and
:js:`executeAbility()` runs them locally.

..  warning::
    Client abilities are **not** governed by the server pipeline: no policy,
    no scopes, no traces. They are a convenience for browser-only work, not
    a way to move a privileged operation into the client.

..  _surfaces-js-client-api:

Exported functions
==================

..  list-table::
    :header-rows: 1
    :widths: 40 60

    *   -   Function
        -   Purpose

    *   -   :js:`getAbilities({category, surface})`
        -   Server abilities merged with client-registered ones.

    *   -   :js:`getAbility(name)`
        -   Full definition including both schemas; `null` when unknown.

    *   -   :js:`executeAbility(name, input, {approveReview})`
        -   Run it; resolves to the envelope plus `status`.

    *   -   :js:`getCategories()`
        -   All categories with an `inUse` flag.

    *   -   :js:`getTokens()` / :js:`createToken()` / :js:`revokeToken(uid)`
        -   REST token management for the acting backend user.

    *   -   :js:`getTraces({ability, surface, ok, limit})`
        -   Execution traces, newest first.

    *   -   :js:`registerAbility()` / :js:`unregisterAbility(name)` /
            :js:`getRegisteredAbilities()`
        -   Client-side ability registry.
