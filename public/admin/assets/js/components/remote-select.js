// Searchable select (WAI-ARIA combobox) backed by /admin-api/lookups.
// Used wherever a form or filter references users, API keys, models, etc., so
// any number of records can be found by typing instead of loading full tables.
//
// The chosen value is kept in a hidden <input name=...> so readFields() reads it
// like any other control: single -> "12", multiple -> "12,15", and for
// multi-source pickers the value is "<prefix>:<id>" (e.g. "user:12").
import { el } from "../dom.js";
import { t } from "../i18n.js";
import { lookupLabel, resolveLookup, searchLookup } from "../lib/lookups.js";

let comboSeq = 0;

function splitValue(value, multiple) {
  if (Array.isArray(value)) {
    return value.map(String).filter(Boolean);
  }
  const raw = value === null || value === undefined ? "" : String(value).trim();
  if (raw === "") {
    return [];
  }
  if (raw.startsWith("[")) {
    try {
      const parsed = JSON.parse(raw);
      if (Array.isArray(parsed)) {
        return parsed.map(String);
      }
    } catch {
      // fall through
    }
  }
  return multiple ? raw.split(",").map((item) => item.trim()).filter(Boolean) : [raw];
}

/**
 * @param {object} options
 * @param {string} options.name form field name
 * @param {string} [options.lookup] lookup type, e.g. "users"
 * @param {Array<{lookup:string,prefix:string,label:string}>} [options.sources] several lookup types in one picker
 * @param {*} [options.value] initial value(s)
 * @param {boolean} [options.multiple]
 * @param {string} [options.emptyLabel] label of the "no selection" choice (single only)
 * @param {object} [options.query] extra lookup filters (e.g. { department_id })
 */
export function remoteSelect({ name, lookup = "", sources = null, value = "", multiple = false, emptyLabel = "", placeholder = t("select.placeholder"), query = {}, disabled = false, label = "" }) {
  const id = `combo-${++comboSeq}`;
  const sourceList = sources || [{ lookup, prefix: "", label: "" }];
  let selected = splitValue(value, multiple).map((key) => ({ key, label: key }));
  let options = [];
  let activeIndex = -1;
  let extraQuery = { ...query };
  let searchTimer = 0;
  let searchSeq = 0;

  const hidden = el("input", { type: "hidden", name });
  const input = el("input", {
    type: "text",
    className: "remote-select-input",
    role: "combobox",
    autocomplete: "off",
    placeholder,
    "aria-autocomplete": "list",
    "aria-expanded": "false",
    "aria-controls": `${id}-list`,
    "aria-label": label || placeholder
  });
  const chips = el("div", { className: "remote-select-chips" });
  const list = el("div", { id: `${id}-list`, className: "remote-select-list", role: "listbox", hidden: true });
  const status = el("div", { className: "visually-hidden", role: "status", "aria-live": "polite" });
  const wrapper = el("div", { className: `remote-select ${multiple ? "is-multiple" : ""}`.trim(), dataset: { remoteSelect: name } }, [chips, input, list, status, hidden]);

  const keyFor = (source, id) => (source.prefix ? `${source.prefix}:${id}` : String(id));
  const parseKey = (key) => {
    if (sourceList.length > 1 || sourceList[0].prefix) {
      const [prefix, rawId] = String(key).split(":");
      return { source: sourceList.find((item) => item.prefix === prefix), id: Number(rawId) };
    }
    return { source: sourceList[0], id: Number(key) };
  };

  function sync() {
    hidden.value = selected.map((item) => item.key).join(",");
    if (multiple) {
      chips.replaceChildren(...selected.map((item) => el("span", { className: "remote-select-chip" }, [
        el("span", { text: item.label }),
        el("button", {
          type: "button",
          className: "remote-select-chip-remove",
          "aria-label": t("select.remove", { label: item.label }),
          text: "×",
          onClick: () => {
            selected = selected.filter((entry) => entry.key !== item.key);
            sync();
            emitChange();
          }
        })
      ])));
    } else {
      input.value = selected[0]?.label || "";
    }
  }

  function emitChange() {
    hidden.dispatchEvent(new Event("change", { bubbles: true }));
    wrapper.dispatchEvent(new CustomEvent("remote-select-change", { bubbles: true, detail: { value: hidden.value } }));
  }

  function open() {
    list.hidden = false;
    input.setAttribute("aria-expanded", "true");
  }

  function close() {
    list.hidden = true;
    input.setAttribute("aria-expanded", "false");
    input.removeAttribute("aria-activedescendant");
    activeIndex = -1;
    if (!multiple) {
      input.value = selected[0]?.label || "";
    }
  }

  function renderOptions(message = "") {
    const rows = [];
    if (!multiple && emptyLabel) {
      rows.push({ key: "", label: emptyLabel });
    }
    rows.push(...options);
    list.replaceChildren(...rows.map((option, index) => {
      const isSelected = selected.some((item) => item.key === option.key) || (!option.key && selected.length === 0);
      return el("div", {
        id: `${id}-opt-${index}`,
        className: `remote-select-option ${index === activeIndex ? "is-active" : ""} ${option.group ? "has-group" : ""}`.trim(),
        role: "option",
        "aria-selected": isSelected ? "true" : "false",
        dataset: { key: option.key },
        onMousedown: (event) => {
          event.preventDefault();
          choose(option);
        }
      }, [
        option.group ? el("small", { className: "remote-select-group", text: option.group }) : null,
        el("span", { text: option.label })
      ]);
    }));
    if (message) {
      list.append(el("div", { className: "remote-select-message", text: message }));
    }
    status.textContent = message || (options.length ? `${options.length}` : t("select.noResults"));
    if (activeIndex >= 0) {
      input.setAttribute("aria-activedescendant", `${id}-opt-${activeIndex}`);
    }
  }

  async function search(keyword) {
    const seq = ++searchSeq;
    renderOptions(t("select.searching"));
    open();
    try {
      const groups = await Promise.all(sourceList.map(async (source) => {
        const rows = await searchLookup(source.lookup, { q: keyword, limit: sourceList.length > 1 ? 10 : 20, ...extraQuery });
        return rows.map((row) => ({ key: keyFor(source, row.id), label: lookupLabel(source.lookup, row), group: source.label }));
      }));
      if (seq !== searchSeq) {
        return;
      }
      options = groups.flat();
      activeIndex = -1;
      renderOptions(options.length ? "" : t("select.noResults"));
    } catch {
      if (seq === searchSeq) {
        options = [];
        renderOptions(t("select.noResults"));
      }
    }
  }

  function choose(option) {
    if (multiple) {
      if (option.key && !selected.some((item) => item.key === option.key)) {
        selected = [...selected, option];
      }
      input.value = "";
    } else {
      selected = option.key ? [option] : [];
    }
    sync();
    emitChange();
    if (multiple) {
      renderOptions();
    } else {
      close();
    }
  }

  input.addEventListener("focus", () => {
    if (!input.disabled) {
      void search(multiple ? input.value.trim() : "");
    }
  });
  input.addEventListener("input", () => {
    // Drop the previous results at once so Enter can never pick a stale option.
    options = [];
    activeIndex = -1;
    searchSeq += 1;
    renderOptions(t("select.searching"));
    open();
    window.clearTimeout(searchTimer);
    searchTimer = window.setTimeout(() => void search(input.value.trim()), 200);
  });
  input.addEventListener("blur", () => window.setTimeout(close, 120));
  input.addEventListener("keydown", (event) => {
    const total = list.querySelectorAll('[role="option"]').length;
    if (event.key === "ArrowDown" || event.key === "ArrowUp") {
      event.preventDefault();
      if (list.hidden) {
        void search(input.value.trim());
        return;
      }
      activeIndex = total === 0 ? -1 : (activeIndex + (event.key === "ArrowDown" ? 1 : -1) + total) % total;
      renderOptions();
      list.querySelector(".is-active")?.scrollIntoView({ block: "nearest" });
    } else if (event.key === "Enter") {
      if (!list.hidden && activeIndex >= 0) {
        event.preventDefault();
        const rows = !multiple && emptyLabel ? [{ key: "", label: emptyLabel }, ...options] : options;
        if (rows[activeIndex]) {
          choose(rows[activeIndex]);
        }
      }
    } else if (event.key === "Escape") {
      if (!list.hidden) {
        event.preventDefault();
        event.stopPropagation();
        close();
      }
    } else if (event.key === "Backspace" && multiple && input.value === "" && selected.length > 0) {
      selected = selected.slice(0, -1);
      sync();
      emitChange();
    }
  });

  async function resolveInitialLabels() {
    if (selected.length === 0) {
      return;
    }
    const bySource = new Map();
    for (const item of selected) {
      const { source, id } = parseKey(item.key);
      if (source && Number.isFinite(id)) {
        if (!bySource.has(source)) {
          bySource.set(source, []);
        }
        bySource.get(source).push(id);
      }
    }
    for (const [source, ids] of bySource) {
      const rows = await resolveLookup(source.lookup, ids);
      selected = selected.map((item) => {
        const parsed = parseKey(item.key);
        if (parsed.source === source && rows.has(parsed.id)) {
          return { key: item.key, label: `${source.label ? `${source.label} / ` : ""}${lookupLabel(source.lookup, rows.get(parsed.id))}` };
        }
        return item;
      });
    }
    sync();
  }

  wrapper.getValue = () => hidden.value;
  wrapper.setValue = (next) => {
    selected = splitValue(next, multiple).map((key) => ({ key, label: key }));
    sync();
    void resolveInitialLabels();
  };
  wrapper.setQuery = (next = {}) => {
    extraQuery = { ...query, ...next };
    options = [];
  };
  wrapper.setDisabled = (value) => {
    input.disabled = Boolean(value);
    wrapper.classList.toggle("is-disabled", Boolean(value));
  };
  wrapper.input = input;

  sync();
  wrapper.setDisabled(disabled);
  void resolveInitialLabels();
  return wrapper;
}
