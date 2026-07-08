import AjaxRequest from "@typo3/core/ajax/ajax-request.js";
import Notification from "@typo3/backend/notification.js";

/**
 * Backend module for the abilities registry. Every "run" posts to a
 * session-guarded backend AJAX route, so no token or extra login is
 * involved — the acting backend user is the identity. Results are the
 * verbatim AbilityResult envelope, the same contract every projection uses.
 */
const urlsNode = document.getElementById("abilities-ajax-urls");
const urls = urlsNode ? JSON.parse(urlsNode.textContent) : {};
const described = new Set();

function group(ability) {
  return document.querySelector(`.abilities-group[data-ability="${CSS.escape(ability)}"]`);
}

function skeletonFromSchema(schema) {
  const out = {};
  const props = (schema && schema.properties) || {};
  const required = (schema && schema.required) || [];
  for (const [key, def] of Object.entries(props)) {
    if (!required.includes(key) && !(def && "default" in def)) continue;
    const type = Array.isArray(def.type) ? def.type[0] : def.type;
    if (def && "default" in def) out[key] = def.default;
    else if (def && def.enum) out[key] = def.enum[0];
    else if (type === "integer" || type === "number") out[key] = def.minimum ?? 0;
    else if (type === "boolean") out[key] = false;
    else if (type === "array") out[key] = [];
    else if (type === "object") out[key] = {};
    else out[key] = "";
  }
  return out;
}

async function call(url, { method = "GET", query = null, body = null } = {}) {
  let request = new AjaxRequest(url);
  if (query) request = request.withQueryArguments(query);
  try {
    const response = method === "POST"
      ? await request.post(JSON.stringify(body), { headers: { "Content-Type": "application/json" } })
      : await request.get();
    return { status: response.response.status, data: await response.resolve() };
  } catch (response) {
    if (response && response.response) {
      const data = await response.resolve().catch(() => null);
      return { status: response.response.status, data };
    }
    throw response;
  }
}

async function describe(ability) {
  if (described.has(ability)) return;
  const box = group(ability);
  const input = box.querySelector(".abilities-input");
  try {
    const { data } = await call(urls.describe, { query: { name: ability } });
    if (data && data.inputSchema) {
      input.value = JSON.stringify(skeletonFromSchema(data.inputSchema), null, 2);
    }
    described.add(ability);
  } catch (e) {
    Notification.error("Abilities", "Could not load the ability contract.");
  }
}

async function execute(ability) {
  const box = group(ability);
  const input = box.querySelector(".abilities-input");
  const approve = box.querySelector(".abilities-approve-input");
  const button = box.querySelector(".abilities-execute");
  const meta = box.querySelector(".abilities-meta");
  const result = box.querySelector(".abilities-result");

  let parsed;
  try {
    parsed = JSON.parse(input.value || "{}");
  } catch (e) {
    Notification.error("Abilities", "Input is not valid JSON: " + e.message);
    return;
  }

  button.disabled = true;
  const started = performance.now();
  try {
    const { status, data } = await call(urls.run, {
      method: "POST",
      body: { name: ability, input: parsed, approveReview: approve ? approve.checked : false },
    });
    const ms = Math.round(performance.now() - started);
    result.textContent = JSON.stringify(data, null, 2);
    result.classList.toggle("abilities-result--ok", !!(data && data.ok));
    result.classList.toggle("abilities-result--fail", !(data && data.ok));
    meta.textContent = `HTTP ${status} · ${ms} ms · traced as surface "backend"`;
    if (data && data.ok) Notification.success("Abilities", `${ability} ran successfully.`);
    else Notification.warning("Abilities", `${ability}: ${(data && data.errorCode) || "failed"}`);
  } catch (e) {
    result.textContent = String(e && e.message ? e.message : e);
    Notification.error("Abilities", "Request failed.");
  } finally {
    button.disabled = false;
  }
}

document.querySelectorAll(".abilities-run-toggle").forEach((toggle) => {
  toggle.addEventListener("click", () => {
    const ability = toggle.dataset.ability;
    const runner = document.querySelector(`.abilities-runner[data-ability="${CSS.escape(ability)}"]`);
    if (!runner) return;
    runner.hidden = !runner.hidden;
    if (!runner.hidden) describe(ability);
  });
});

document.querySelectorAll(".abilities-execute").forEach((button) => {
  button.addEventListener("click", () => execute(button.dataset.ability));
});
