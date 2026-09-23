import Notification from "@typo3/backend/notification.js";
import Modal from "@typo3/backend/modal.js";
import Severity from "@typo3/backend/severity.js";
import labels from "~labels/abilities.mod";
import {
  executeAbility,
  getAbility,
  getTokens,
  createToken,
  revokeToken,
  getTraces,
} from "@webconsulting/abilities/client.js";

/**
 * Backend module of the abilities registry: five tabs over the same governed
 * pipeline every other surface uses. Registry browses and filters what is
 * registered, Catalogue shows everything the installation can do from every
 * source, Run generates a form from the ability's inputSchema, Traces shows
 * what actually ran, Tokens manages the REST bearer tokens of the acting
 * backend user.
 *
 * Nothing here talks to the database or the executor directly — every call
 * goes through the session-guarded backend AJAX routes (see client.js), so
 * the logged-in backend user is the identity and their be_groups scopes
 * apply exactly as on CLI, MCP and REST.
 */

const root = document.querySelector(".abilities-module");

if (root) {
  initFilterGroups();
  initRegistryTab();
  initRunTab();
  initTracesTab();
  initTokensTab();
}

/* ─────────────────────────── helpers ─────────────────────────── */

const locale = document.documentElement.lang || undefined;
const notificationTitle = labels.get("js.notification.title");

function formatTimestamp(seconds) {
  if (!seconds) {
    return "—";
  }
  return new Date(seconds * 1000).toLocaleString(locale);
}

function riskBadge(tier) {
  return badge(labels.get(`risk.${tier}`), tier === "low" ? "success" : tier === "medium" ? "warning" : "danger");
}

function text(tag, content, className) {
  const element = document.createElement(tag);
  if (className) {
    element.className = className;
  }
  element.textContent = content;
  return element;
}

function cell(content, className) {
  return text("td", content, className);
}

function badge(label, variant) {
  return text("span", label, `badge badge-${variant}`);
}

function emptyRow(table, message, columns) {
  const row = document.createElement("tr");
  const td = cell(message, "text-muted");
  td.colSpan = columns;
  row.append(td);
  table.tBodies[0].replaceChildren(row);
}

/* ─────────────────────────── Filterable tables ─────────────────────────── */

/**
 * One filter group drives one table: the group's data-filter-rows selects
 * the rows, every control with data-filter="<key>" matches against the
 * row's data-<key>. The "haystack" key is a substring search, every other
 * key an exact match — "surfaces" against a space-separated list.
 * Used by the Registry and the Catalogue tab.
 */
function initFilterGroups() {
  for (const group of document.querySelectorAll("[data-filter-rows]")) {
    const rows = Array.from(document.querySelectorAll(group.dataset.filterRows));
    const controls = Array.from(group.querySelectorAll("[data-filter]"));
    const status = group.querySelector("[data-filter-status]");
    if (rows.length === 0 || controls.length === 0) {
      continue;
    }

    const matches = (row, control) => {
      const key = control.dataset.filter;
      const value = control.value.trim();
      if (value === "") {
        return true;
      }
      const candidate = row.dataset[key] ?? "";
      if (key === "haystack") {
        return candidate.toLowerCase().includes(value.toLowerCase());
      }
      if (key === "surfaces") {
        return candidate.split(" ").includes(value);
      }
      return candidate === value;
    };

    const apply = () => {
      let visible = 0;
      for (const row of rows) {
        const shown = controls.every((control) => matches(row, control));
        row.hidden = !shown;
        if (shown) {
          visible++;
        }
      }
      if (status) {
        status.textContent = labels.get("js.filter.shown", { visible, total: rows.length });
      }
    };

    for (const control of controls) {
      control.addEventListener("input", apply);
    }
    apply();
  }
}

/* ─────────────────────────── Registry tab ─────────────────────────── */

function initRegistryTab() {
  for (const button of document.querySelectorAll(".abilities-open-run")) {
    button.addEventListener("click", () => {
      const select = document.getElementById("abilities-run-select");
      select.value = button.dataset.ability;
      select.dispatchEvent(new Event("change"));
      document.querySelector('[data-typo3-tab="#abilities-tab-run"]').click();
      select.focus();
    });
  }
}

/* ─────────────────────────── Run tab ─────────────────────────── */

/**
 * One form control per top-level property of the input schema:
 * enum → select, boolean → checkbox, integer/number → number input,
 * string → text input (textarea for long text), everything else
 * (object, array, union types) → a JSON textarea, because a generic form
 * cannot honestly represent them.
 */
function buildField(name, schema, required) {
  const types = Array.isArray(schema.type) ? schema.type : [schema.type];
  const type = types.find((candidate) => candidate !== "null") ?? "string";
  const nullable = types.includes("null");
  const id = `abilities-field-${name}`;

  const wrapper = document.createElement("div");
  wrapper.className = "form-group abilities-field";

  const label = document.createElement("label");
  label.className = "form-label";
  label.htmlFor = id;
  label.textContent = name;
  if (required) {
    const marker = text("span", " *", "text-danger");
    marker.setAttribute("aria-hidden", "true");
    label.append(marker);
  }

  let control;
  let kind = type;

  if (Array.isArray(schema.enum)) {
    kind = "enum";
    control = document.createElement("select");
    control.className = "form-select";
    if (!required || nullable) {
      control.append(new Option("—", ""));
    }
    for (const option of schema.enum) {
      control.append(new Option(String(option), String(option)));
    }
  } else if (type === "boolean") {
    control = document.createElement("input");
    control.type = "checkbox";
    control.className = "form-check-input";
  } else if (type === "integer" || type === "number") {
    control = document.createElement("input");
    control.type = "number";
    control.className = "form-control";
    if (type === "integer") {
      control.step = "1";
    }
    if (typeof schema.minimum === "number") {
      control.min = String(schema.minimum);
    }
    if (typeof schema.maximum === "number") {
      control.max = String(schema.maximum);
    }
  } else if (type === "object" || type === "array") {
    kind = "json";
    control = document.createElement("textarea");
    control.className = "form-control abilities-json";
    control.rows = 4;
    control.spellcheck = false;
  } else {
    control = document.createElement("input");
    control.type = "text";
    control.className = "form-control";
    if (typeof schema.minLength === "number") {
      control.minLength = schema.minLength;
    }
    if (typeof schema.maxLength === "number" && schema.maxLength <= 255) {
      control.maxLength = schema.maxLength;
    }
    if (typeof schema.pattern === "string") {
      control.pattern = schema.pattern;
    }
  }

  control.id = id;
  control.name = name;
  if (required && type !== "boolean") {
    control.setAttribute("aria-required", "true");
  }
  control.dataset.kind = kind;
  control.dataset.nullable = nullable ? "1" : "";
  control.dataset.required = required ? "1" : "";

  if (schema.default !== undefined && schema.default !== null) {
    if (kind === "json") {
      control.value = JSON.stringify(schema.default, null, 2);
    } else if (type === "boolean") {
      control.checked = schema.default === true;
    } else {
      control.value = String(schema.default);
    }
  }

  if (schema.description) {
    control.setAttribute("aria-describedby", `${id}-help`);
  }

  if (type === "boolean") {
    const check = document.createElement("div");
    check.className = "form-check";
    label.className = "form-check-label";
    check.append(control, label);
    wrapper.append(check);
  } else {
    wrapper.append(label, control);
  }

  if (schema.description) {
    const help = text("p", schema.description, "form-text");
    help.id = `${id}-help`;
    wrapper.append(help);
  }

  return wrapper;
}

/**
 * Read the generated form back into an input object. Empty optional fields
 * are omitted rather than sent as "", so the ability's schema defaults apply.
 */
function collectInput(container) {
  const input = {};
  for (const control of container.querySelectorAll("[data-kind]")) {
    const { name } = control;
    const kind = control.dataset.kind;
    const required = control.dataset.required === "1";

    if (kind === "boolean") {
      input[name] = control.checked;
      continue;
    }

    const raw = control.value.trim();
    if (raw === "") {
      if (control.dataset.nullable && required) {
        input[name] = null;
      }
      continue;
    }

    if (kind === "integer") {
      input[name] = Number.parseInt(raw, 10);
    } else if (kind === "number") {
      input[name] = Number.parseFloat(raw);
    } else if (kind === "json") {
      input[name] = JSON.parse(raw); // caller catches SyntaxError
    } else {
      input[name] = raw;
    }
  }
  return input;
}

function initRunTab() {
  const select = document.getElementById("abilities-run-select");
  if (!select) {
    return;
  }
  const panel = document.getElementById("abilities-run-panel");
  const form = document.getElementById("abilities-run-form");
  const fields = document.getElementById("abilities-run-fields");
  const title = document.querySelector(".abilities-run-title");
  const description = document.querySelector(".abilities-run-description");
  const instructions = document.querySelector(".abilities-run-instructions");
  const meta = document.querySelector(".abilities-run-meta");
  const approveWrapper = document.getElementById("abilities-approve-wrapper");
  const approve = document.getElementById("abilities-approve");
  const approveReason = document.querySelector(".abilities-approve-reason");
  const result = document.getElementById("abilities-result");
  const status = document.querySelector(".abilities-run-status");
  const execute = document.getElementById("abilities-execute");
  let current = null;

  const render = (ability) => {
    current = ability;
    title.textContent = `${ability.name} — ${ability.title}`;
    description.textContent = ability.description ?? "";
    instructions.textContent = ability.instructions ?? "";

    meta.replaceChildren();
    meta.append(riskBadge(ability.riskTier));
    if (ability.readOnly) {
      meta.append(document.createTextNode(" "), badge(labels.get("annotation.readonly"), "info"));
    }
    if (ability.destructive) {
      meta.append(document.createTextNode(" "), badge(labels.get("annotation.destructive"), "danger"));
    }
    if (ability.idempotent) {
      meta.append(document.createTextNode(" "), badge(labels.get("annotation.idempotent"), "default"));
    }
    if (Array.isArray(ability.scopes) && ability.scopes.length > 0) {
      meta.append(document.createTextNode(" "), badge(ability.scopes.join(", "), "default"));
    }

    const schema = ability.inputSchema ?? {};
    const properties = schema.properties ?? {};
    const required = Array.isArray(schema.required) ? schema.required : [];
    fields.replaceChildren();
    const names = Object.keys(properties);
    if (names.length === 0) {
      fields.append(text("p", labels.get("js.run.noInput"), "text-muted"));
    }
    for (const name of names) {
      fields.append(buildField(name, properties[name] ?? {}, required.includes(name)));
    }

    const policy = ability.policy ?? {};
    approveWrapper.hidden = !policy.reviewRequired;
    approve.checked = false;
    approveReason.textContent = policy.reviewRequired ? policy.reason ?? "" : "";
    execute.disabled = policy.allowed === false && !policy.reviewRequired;
    if (execute.disabled) {
      status.textContent = policy.reason ?? labels.get("js.run.denied");
    } else {
      status.textContent = "";
    }

    result.replaceChildren(text("span", labels.get("run.result.empty"), "text-muted"));
    result.classList.remove("abilities-result--ok", "abilities-result--fail");
    panel.hidden = false;
  };

  select.addEventListener("change", async () => {
    if (select.value === "") {
      panel.hidden = true;
      current = null;
      return;
    }
    try {
      const ability = await getAbility(select.value);
      if (ability) {
        render(ability);
      }
    } catch (error) {
      Notification.error(notificationTitle, labels.get("js.run.loadFailed", { message: error.message }));
    }
  });

  document.getElementById("abilities-reset").addEventListener("click", () => {
    if (current) {
      render(current);
    }
  });

  form.addEventListener("submit", async (event) => {
    event.preventDefault();
    if (!current) {
      return;
    }

    let input;
    try {
      input = collectInput(fields);
    } catch (error) {
      Notification.error(notificationTitle, labels.get("js.run.invalidJson", { message: error.message }));
      return;
    }

    const run = async () => {
      execute.disabled = true;
      const started = performance.now();
      try {
        const response = await executeAbility(current.name, input, { approveReview: approve.checked });
        const ms = Math.round(performance.now() - started);
        const { status: httpStatus, traceUid, ...envelope } = response;
        result.textContent = JSON.stringify(envelope, null, 2);
        result.classList.toggle("abilities-result--ok", envelope.ok === true);
        result.classList.toggle("abilities-result--fail", envelope.ok !== true);
        status.textContent = traceUid
          ? labels.get("js.run.statusWithTrace", { status: httpStatus, ms, trace: traceUid })
          : labels.get("js.run.status", { status: httpStatus, ms });
        if (envelope.ok) {
          Notification.success(notificationTitle, labels.get("js.run.success", { name: current.name }));
        } else {
          Notification.warning(notificationTitle, labels.get("js.run.failed", { name: current.name, code: envelope.errorCode ?? "ability_cannot_execute" }));
        }
      } catch (error) {
        result.textContent = String(error?.message ?? error);
        result.classList.add("abilities-result--fail");
        Notification.error(notificationTitle, labels.get("js.requestFailed"));
      } finally {
        execute.disabled = false;
      }
    };

    // A destructive ability gets a confirmation, exactly like deleting a
    // record anywhere else in the backend.
    if (current.destructive) {
      const modal = Modal.confirm(
        labels.get("js.run.confirm.title", { name: current.name }),
        current.instructions || current.description,
        Severity.warning,
        [
          { text: labels.get("js.cancel"), active: true, btnClass: "btn-default", name: "cancel", trigger: () => modal.hideModal() },
          {
            text: labels.get("js.run.confirm.run"),
            btnClass: "btn-warning",
            name: "run",
            trigger: () => {
              modal.hideModal();
              run();
            },
          },
        ],
      );
      return;
    }

    await run();
  });
}

/* ─────────────────────────── Traces tab ─────────────────────────── */

function initTracesTab() {
  const table = document.getElementById("abilities-traces-table");
  if (!table) {
    return;
  }
  const ability = document.getElementById("abilities-trace-ability");
  const surface = document.getElementById("abilities-trace-surface");
  const outcome = document.getElementById("abilities-trace-outcome");
  const status = document.querySelector(".abilities-trace-status");
  let surfacesLoaded = false;

  const load = async () => {
    status.textContent = labels.get("js.loading");
    try {
      const { traces, totalStored, surfaces } = await getTraces({
        ability: ability.value,
        surface: surface.value,
        ok: outcome.value,
      });

      if (!surfacesLoaded) {
        for (const value of surfaces) {
          surface.append(new Option(value, value));
        }
        surfacesLoaded = true;
      }

      if (traces.length === 0) {
        emptyRow(table, labels.get("js.traces.empty"), 8);
        status.textContent = labels.get("js.traces.status", { shown: 0, stored: totalStored });
        return;
      }

      table.tBodies[0].replaceChildren(
        ...traces.map((trace) => {
          const row = document.createElement("tr");
          const outcomeCell = document.createElement("td");
          outcomeCell.append(
            trace.ok ? badge(labels.get("js.traces.ok"), "success") : badge(trace.errorCode || labels.get("js.traces.failed"), "danger"),
          );
          if (!trace.ok && trace.error) {
            outcomeCell.append(text("div", trace.error, "abilities-subtitle"));
          }
          const input = document.createElement("td");
          input.append(text("code", trace.input, "abilities-trace-input"));
          row.append(
            cell(`#${trace.uid}`),
            cell(formatTimestamp(trace.crdate)),
            cell(trace.ability),
            cell(trace.surface),
            outcomeCell,
            cell(`${trace.durationMs} ms`),
            cell(trace.beUser > 0 ? `#${trace.beUser}` : "—"),
            input,
          );
          return row;
        }),
      );
      status.textContent = labels.get("js.traces.status", { shown: traces.length, stored: totalStored });
    } catch (error) {
      status.textContent = "";
      Notification.error(notificationTitle, labels.get("js.traces.loadFailed", { message: error.message }));
    }
  };

  for (const control of [ability, surface, outcome]) {
    control.addEventListener("change", load);
  }
  document.getElementById("abilities-trace-reload").addEventListener("click", load);

  // Load lazily: the tab is not visible until it is selected.
  document
    .querySelector('[data-typo3-tab="#abilities-tab-traces"]')
    .addEventListener("click", () => load(), { once: true });
}

/* ─────────────────────────── Tokens tab ─────────────────────────── */

function initTokensTab() {
  const table = document.getElementById("abilities-tokens-table");
  if (!table) {
    return;
  }
  const form = document.getElementById("abilities-token-form");
  const nameField = document.getElementById("abilities-token-name");
  const scopesField = document.getElementById("abilities-token-scopes");
  const expiresField = document.getElementById("abilities-token-expires");
  const plaintextBox = document.getElementById("abilities-token-plaintext");
  const plaintextValue = plaintextBox.querySelector(".abilities-token-value");
  const plaintextCopy = plaintextBox.querySelector("#abilities-token-copy");
  const status = document.querySelector(".abilities-token-status");
  let scopesLoaded = false;

  const load = async () => {
    try {
      const { tokens, scopes } = await getTokens();

      if (!scopesLoaded) {
        scopesField.append(new Option(labels.get("js.tokens.allScopes"), "*"));
        for (const scope of scopes) {
          scopesField.append(new Option(scope, scope));
        }
        scopesLoaded = true;
      }

      if (tokens.length === 0) {
        emptyRow(table, labels.get("js.tokens.empty"), 7);
        status.textContent = "";
        return;
      }

      const now = Math.floor(Date.now() / 1000);
      table.tBodies[0].replaceChildren(
        ...tokens.map((token) => {
          const row = document.createElement("tr");
          const expires = document.createElement("td");
          if (token.expires > 0) {
            expires.textContent = formatTimestamp(token.expires);
            if (token.expires <= now) {
              expires.append(document.createTextNode(" "), badge(labels.get("js.tokens.expired"), "danger"));
            }
          } else {
            expires.textContent = labels.get("js.tokens.never");
          }

          const actions = document.createElement("td");
          actions.className = "col-control";
          const revoke = document.createElement("button");
          revoke.type = "button";
          revoke.className = "btn btn-default btn-sm";
          revoke.textContent = labels.get("js.tokens.revoke");
          revoke.setAttribute("aria-label", labels.get("js.tokens.revokeNamed", { name: token.name }));
          revoke.addEventListener("click", () => {
            const modal = Modal.confirm(
              labels.get("js.tokens.revoke.title"),
              labels.get("js.tokens.revoke.message", { name: token.name }),
              Severity.warning,
              [
                { text: labels.get("js.cancel"), active: true, btnClass: "btn-default", name: "cancel", trigger: () => modal.hideModal() },
                {
                  text: labels.get("js.tokens.revoke"),
                  btnClass: "btn-danger",
                  name: "revoke",
                  trigger: async () => {
                    modal.hideModal();
                    try {
                      await revokeToken(token.uid);
                      Notification.success(notificationTitle, labels.get("js.tokens.revoked", { name: token.name }));
                      await load();
                    } catch (error) {
                      Notification.error(notificationTitle, labels.get("js.tokens.revokeFailed", { message: error.message }));
                    }
                  },
                },
              ],
            );
          });
          actions.append(revoke);

          row.append(
            cell(`#${token.uid}`),
            cell(token.name),
            cell(token.beUser > 0 ? `#${token.beUser}` : "—"),
            cell(token.scopes.length > 0 ? token.scopes.join(", ") : "—"),
            expires,
            cell(token.lastUsed > 0 ? formatTimestamp(token.lastUsed) : labels.get("js.tokens.never")),
            actions,
          );
          return row;
        }),
      );
      status.textContent = labels.get("js.tokens.count", { count: tokens.length });
    } catch (error) {
      Notification.error(notificationTitle, labels.get("js.tokens.loadFailed", { message: error.message }));
    }
  };

  form.addEventListener("submit", async (event) => {
    event.preventDefault();
    const name = nameField.value.trim();
    if (name === "") {
      nameField.focus();
      return;
    }

    try {
      const issued = await createToken({
        name,
        scopes: Array.from(scopesField.selectedOptions, (option) => option.value),
        expiresInDays: expiresField.value === "" ? null : Number.parseInt(expiresField.value, 10),
      });

      // The plaintext exists exactly once: show it, never store it.
      plaintextValue.textContent = issued.token;
      if (plaintextCopy) {
        plaintextCopy.text = issued.token;
      }
      plaintextBox.hidden = false;
      plaintextValue.focus();
      Notification.success(
        notificationTitle,
        labels.get("js.tokens.created", { name: issued.name, scopes: issued.effectiveScopes.join(", ") || labels.get("js.tokens.noScopes") }),
      );
      form.reset();
      await load();
    } catch (error) {
      Notification.error(notificationTitle, labels.get("js.tokens.createFailed", { message: error.message }));
    }
  });

  document
    .querySelector('[data-typo3-tab="#abilities-tab-tokens"]')
    .addEventListener("click", () => load(), { once: true });
}
