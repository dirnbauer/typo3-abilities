import AjaxRequest from "@typo3/core/ajax/ajax-request.js";

/**
 * Backend client for the abilities registry — the JavaScript counterpart of
 * the WordPress `wp.abilities` client API. Server abilities are read and
 * executed through the session-guarded backend AJAX routes (no token, the
 * logged-in backend user is the identity; scopes come from be_groups).
 * Client-side abilities registered with registerAbility() live in this
 * module only and execute in the browser.
 *
 *   import { getAbilities, executeAbility } from "@webconsulting/abilities/client.js";
 *   const { abilities } = await getAbilities({ category: "system" });
 *   const result = await executeAbility("system/site-info", {});
 */

const NAME_PATTERN = /^[a-z0-9][a-z0-9-]*\/[a-z0-9][a-z0-9-]*$/;
const clientAbilities = new Map();

function routeUrl(route) {
  const urls = (globalThis.TYPO3 && TYPO3.settings && TYPO3.settings.ajaxUrls) || {};
  const url = urls[route];
  if (!url) {
    throw new Error(`Backend AJAX route "${route}" is not available; the abilities module must be loaded.`);
  }
  return url;
}

async function request(route, { method = "GET", query = null, body = null } = {}) {
  let ajax = new AjaxRequest(routeUrl(route));
  if (query) {
    ajax = ajax.withQueryArguments(query);
  }
  try {
    const response = method === "POST"
      ? await ajax.post(JSON.stringify(body ?? {}), { headers: { "Content-Type": "application/json" } })
      : await ajax.get();
    return { status: response.response.status, data: await response.resolve() };
  } catch (error) {
    if (error && error.response) {
      const data = await error.resolve().catch(() => null);
      return { status: error.response.status, data };
    }
    throw error;
  }
}

function toClientDefinition(ability) {
  return {
    name: ability.name,
    title: ability.title,
    description: ability.description,
    category: ability.category,
    scopes: [],
    riskTier: ability.riskTier,
    sideEffects: ability.sideEffects,
    idempotent: ability.idempotent,
    destructive: ability.destructive,
    readOnly: ability.readOnly,
    instructions: ability.instructions,
    annotations: {
      readonly: ability.readOnly,
      destructive: ability.destructive,
      idempotent: ability.idempotent,
      instructions: ability.instructions,
    },
    expose: ["client"],
    meta: ability.meta,
    inputSchema: ability.inputSchema,
    outputSchema: ability.outputSchema,
    client: true,
  };
}

/**
 * Register an ability that lives in the browser. Shape mirrors #[AsAbility]:
 * { name, title, description, category, inputSchema, outputSchema, execute(input),
 *   readOnly, destructive, idempotent, instructions, riskTier, sideEffects, meta }.
 * Returns the normalized definition.
 */
export function registerAbility(definition) {
  if (!definition || typeof definition !== "object") {
    throw new TypeError("registerAbility() expects a definition object.");
  }
  const { name } = definition;
  if (typeof name !== "string" || !NAME_PATTERN.test(name)) {
    throw new TypeError(`Ability name "${name}" is invalid; expected "namespace/ability-name" in lowercase kebab-case.`);
  }
  if (typeof definition.execute !== "function") {
    throw new TypeError(`Client ability "${name}" needs an execute(input) function.`);
  }
  if (clientAbilities.has(name)) {
    throw new Error(`Client ability "${name}" is already registered.`);
  }
  const sideEffects = Array.isArray(definition.sideEffects) ? definition.sideEffects : [];
  const ability = {
    name,
    title: definition.title ?? definition.label ?? name,
    description: definition.description ?? "",
    category: definition.category ?? "general",
    inputSchema: definition.inputSchema ?? {},
    outputSchema: definition.outputSchema ?? {},
    execute: definition.execute,
    riskTier: definition.riskTier ?? "low",
    sideEffects,
    idempotent: definition.idempotent === true,
    destructive: definition.destructive === true,
    readOnly: typeof definition.readOnly === "boolean" ? definition.readOnly : sideEffects.length === 0,
    instructions: definition.instructions ?? "",
    meta: definition.meta ?? {},
  };
  clientAbilities.set(name, ability);
  return toClientDefinition(ability);
}

export function unregisterAbility(name) {
  return clientAbilities.delete(name);
}

export function getRegisteredAbilities() {
  return Array.from(clientAbilities.values(), toClientDefinition);
}

/**
 * Server abilities (via the backend) merged with client-side ones.
 * Returns { abilities, total }.
 */
export async function getAbilities({ category = null, surface = null } = {}) {
  const query = {};
  if (category) query.category = category;
  if (surface) query.surface = surface;
  const { status, data } = await request("abilities_list", { query });
  if (status !== 200 || !data) {
    throw new Error(`Could not list abilities (HTTP ${status}).`);
  }
  const clientSide = getRegisteredAbilities().filter((ability) =>
    (!category || ability.category === category) && (!surface || surface === "client"),
  );
  const abilities = [...data.abilities, ...clientSide];
  return { abilities, total: abilities.length };
}

/**
 * Full definition including input/output schemas; null when unknown.
 */
export async function getAbility(name) {
  if (clientAbilities.has(name)) {
    return toClientDefinition(clientAbilities.get(name));
  }
  const { status, data } = await request("abilities_describe", { query: { name } });
  return status === 200 ? data : null;
}

/**
 * The ability catalogue of the whole installation: abilities, native MCP
 * tools, agent skills, REST/webhook endpoints and console commands, each
 * with its input schema, annotations and per-surface invocations.
 * Filters: source, surface, search. Returns { entries, total, sources }.
 */
export async function getCatalog({ source = "", surface = "", search = "" } = {}) {
  const query = {};
  if (source) query.source = source;
  if (surface) query.surface = surface;
  if (search) query.search = search;
  const { status, data } = await request("abilities_catalog", { query });
  if (status !== 200 || !data) {
    throw new Error(`Could not load the ability catalogue (HTTP ${status}).`);
  }
  return data;
}

export async function getCategories() {
  const { status, data } = await request("abilities_categories");
  if (status !== 200 || !data) {
    throw new Error(`Could not list categories (HTTP ${status}).`);
  }
  return data.categories;
}

/**
 * Active REST bearer tokens plus every scope the registry declares.
 * Returns { tokens, total, scopes }.
 */
export async function getTokens() {
  const { status, data } = await request("abilities_tokens");
  if (status !== 200 || !data) {
    throw new Error(`Could not list tokens (HTTP ${status}).`);
  }
  return data;
}

/**
 * Issue a token for the logged-in backend user. The returned object carries
 * the plaintext in `token` — it exists exactly once and is never retrievable
 * again, so show it to the user immediately and do not persist it.
 */
export async function createToken({ name, scopes = [], expiresInDays = null } = {}) {
  const { status, data } = await request("abilities_token_create", {
    method: "POST",
    body: { name, scopes, expiresInDays },
  });
  if (status !== 201 || !data) {
    throw new Error((data && data.error) || `Could not create the token (HTTP ${status}).`);
  }
  return data;
}

export async function revokeToken(uid) {
  const { status, data } = await request("abilities_token_revoke", { method: "POST", body: { uid } });
  if (status !== 200) {
    throw new Error((data && data.error) || `Could not revoke the token (HTTP ${status}).`);
  }
  return true;
}

/**
 * Execution traces, newest first. Filters: ability, surface, ok ("1"/"0"),
 * limit. Returns { traces, total, totalStored, surfaces }.
 */
export async function getTraces({ ability = "", surface = "", ok = "", limit = 50 } = {}) {
  const query = { limit };
  if (ability) query.ability = ability;
  if (surface) query.surface = surface;
  if (ok !== "") query.ok = ok;
  const { status, data } = await request("abilities_traces", { query });
  if (status !== 200 || !data) {
    throw new Error(`Could not list traces (HTTP ${status}).`);
  }
  return data;
}

/**
 * Execute an ability. Always resolves to the result envelope
 * { ok: true, data } | { ok: false, errorCode, error } plus the HTTP status
 * (0 for client-side abilities); it never throws on a governed denial.
 */
export async function executeAbility(name, input = {}, { approveReview = false } = {}) {
  if (clientAbilities.has(name)) {
    const ability = clientAbilities.get(name);
    try {
      const data = await ability.execute(input ?? {});
      return { ok: true, data, status: 0 };
    } catch (error) {
      return {
        ok: false,
        errorCode: "ability_cannot_execute",
        error: error && error.message ? error.message : String(error),
        status: 0,
      };
    }
  }
  const { status, data } = await request("abilities_run", {
    method: "POST",
    body: { name, input: input ?? {}, approveReview: approveReview === true },
  });
  if (data && typeof data === "object") {
    return { ...data, status };
  }
  return { ok: false, errorCode: "ability_cannot_execute", error: `Unexpected response (HTTP ${status}).`, status };
}

export default {
  getAbilities,
  getAbility,
  getCatalog,
  getCategories,
  getTokens,
  createToken,
  revokeToken,
  getTraces,
  executeAbility,
  registerAbility,
  unregisterAbility,
  getRegisteredAbilities,
};
