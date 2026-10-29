import { isAbortError } from "./api.js";
import { toastError } from "./components/feedback.js";
import { openModal } from "./components/modal.js";
import { remoteSelect } from "./components/remote-select.js";
import { t } from "./i18n.js";

export const $ = (id) => document.getElementById(id);

export function setText(node, value) {
  node.textContent = value == null ? "" : String(value);
  return node;
}

export function clear(node) {
  node.replaceChildren();
  return node;
}

export function el(tag, attrs = {}, children = []) {
  const node = document.createElement(tag);
  for (const [key, value] of Object.entries(attrs)) {
    if (value === undefined || value === null || value === false) {
      continue;
    }
    if (key === "className") {
      node.className = value;
    } else if (key === "text") {
      setText(node, value);
    } else if (key === "dataset") {
      for (const [dataKey, dataValue] of Object.entries(value)) {
        node.dataset[dataKey] = dataValue;
      }
    } else if (key.startsWith("on") && typeof value === "function") {
      node.addEventListener(key.slice(2).toLowerCase(), value);
    } else if (key in node) {
      node[key] = value;
    } else {
      node.setAttribute(key, value);
    }
  }
  node.append(...[].concat(children).filter(Boolean));
  return node;
}

/**
 * Button whose click handler may be async. While the handler runs the button is
 * marked busy and further clicks are ignored (no double submission), and a
 * rejected handler is reported to the user instead of failing silently.
 */
export function button(label, onClick, variant = "", attrs = {}) {
  const node = el("button", { type: "button", className: variant, text: label, ...attrs });
  if (typeof onClick === "function") {
    node.addEventListener("click", async (event) => {
      if (node.getAttribute("aria-busy") === "true") {
        event.preventDefault();
        return;
      }
      let result;
      try {
        result = onClick(event);
      } catch (error) {
        toastError(error);
        return;
      }
      if (!result || typeof result.then !== "function") {
        return;
      }
      node.setAttribute("aria-busy", "true");
      node.classList.add("is-busy");
      try {
        await result;
      } catch (error) {
        if (!isAbortError(error)) {
          toastError(error);
        }
      } finally {
        node.removeAttribute("aria-busy");
        node.classList.remove("is-busy");
      }
    });
  }
  return node;
}

let fieldSeq = 0;

function isRequired(config, mode = "create") {
  if (config.required === true) {
    return true;
  }
  if (config.optional || config.required === false) {
    return false;
  }
  if (config.type === "password") {
    return mode === "create";
  }
  if (/留空|可选|选填/.test(String(config.label || ""))) {
    return false;
  }
  return !config.type || config.type === "text" || config.type === "textarea" || config.type === "remote-select";
}

function multiValue(value) {
  if (Array.isArray(value)) {
    return value;
  }
  if (value != null && String(value).trim() !== "") {
    try {
      const parsed = JSON.parse(String(value));
      return Array.isArray(parsed) ? parsed : String(value).split(",");
    } catch {
      return String(value).split(",");
    }
  }
  return [];
}

export function field(config, value = "", { mode = "create" } = {}) {
  const isMultiselect = config.type === "multiselect";
  const isRemote = config.type === "remote-select" || config.type === "remote-multiselect";
  const required = config.validate === false ? false : isRequired(config, mode);
  const id = `field-${++fieldSeq}`;
  const wrapper = el(isMultiselect || isRemote ? "div" : "label", { className: `field ${required ? "is-required" : ""}`.trim(), dataset: { field: config.name } });
  const labelNode = el("span", { id: `${id}-label`, className: "field-label", text: config.label });
  wrapper.append(labelNode);
  let control;
  if (config.type === "select") {
    control = el("select", { name: config.name });
    for (const [optionValue, optionLabel] of config.options || []) {
      control.append(el("option", { value: optionValue, text: optionLabel }));
    }
  } else if (isRemote) {
    control = remoteSelect({
      name: config.name,
      lookup: config.lookup,
      sources: config.sources || null,
      value,
      multiple: config.type === "remote-multiselect",
      emptyLabel: config.emptyOption || (config.optional && config.type === "remote-select" ? t("common.none") : ""),
      query: config.lookupQuery || {},
      label: config.label
    });
    control.input.setAttribute("aria-labelledby", `${id}-label`);
  } else if (isMultiselect) {
    const selected = new Set(multiValue(value).map((item) => String(item).trim()).filter(Boolean));
    const list = el("div", {
      className: "multiselect-list",
      role: "group",
      "aria-labelledby": `${id}-label`,
      style: config.size ? `max-height:${Math.max(6, Number(config.size) || 6) * 1.75}rem` : undefined
    });
    for (const [optionValue, optionLabel] of config.options || []) {
      const checkbox = el("input", {
        type: "checkbox",
        name: config.name,
        value: optionValue,
        checked: selected.has(String(optionValue))
      });
      list.append(el("label", { className: "multiselect-item" }, [
        checkbox,
        el("span", { text: optionLabel })
      ]));
    }
    if ((config.options || []).length === 0) {
      list.append(el("p", { className: "multiselect-empty", text: "暂无可选项" }));
    }
    const toolbar = el("div", { className: "multiselect-toolbar" }, [
      button(t("common.selectAll"), () => {
        list.querySelectorAll('input[type="checkbox"]').forEach((input) => {
          input.checked = true;
        });
        list.dispatchEvent(new Event("change", { bubbles: true }));
      }, "secondary"),
      button(t("common.clear"), () => {
        list.querySelectorAll('input[type="checkbox"]').forEach((input) => {
          input.checked = false;
        });
        list.dispatchEvent(new Event("change", { bubbles: true }));
      }, "secondary")
    ]);
    control = el("div", { className: "multiselect-field" }, [toolbar, list]);
  } else if (config.type === "textarea" || config.type === "json" || config.type === "cidr-list") {
    control = el("textarea", { name: config.name, rows: config.rows || 3 });
    if (config.type === "json") {
      control.spellcheck = false;
      control.classList.add("is-code");
    }
  } else {
    control = el("input", { name: config.name, type: config.type || "text" });
    if (config.type === "number") {
      control.inputMode = "decimal";
      control.step = "any";
    }
  }
  if (control instanceof HTMLInputElement || control instanceof HTMLTextAreaElement || control instanceof HTMLSelectElement) {
    if ("autocomplete" in control) {
      control.autocomplete = config.type === "password" ? "new-password" : "off";
    }
    if (config.placeholder) {
      control.placeholder = config.placeholder;
    }
    const text = value == null ? "" : String(value);
    // A select keeps its first option when there is no value to restore and no
    // empty option, instead of showing a blank (unsubmittable) choice.
    if (!(control instanceof HTMLSelectElement) || text !== "" || [...control.options].some((option) => option.value === "")) {
      control.value = text;
    }
    if (required) {
      control.setAttribute("aria-required", "true");
    }
    control.setAttribute("aria-describedby", `${id}-error`);
    if (config.type === "json") {
      control.addEventListener("blur", () => validateControl(wrapper, config, mode));
    }
  }
  wrapper.append(control);
  if (config.hint) {
    wrapper.append(el("small", { className: "field-hint", text: config.hint }));
  }
  wrapper.append(el("small", { id: `${id}-error`, className: "field-error", hidden: true }));
  return wrapper;
}

function setFieldError(wrapper, message) {
  if (!wrapper) {
    return;
  }
  const error = wrapper.querySelector(":scope > .field-error");
  const control = wrapper.querySelector(":scope > input, :scope > select, :scope > textarea, :scope .remote-select-input");
  wrapper.classList.toggle("has-error", Boolean(message));
  if (error) {
    error.hidden = !message;
    error.textContent = message || "";
  }
  if (control) {
    if (message) {
      control.setAttribute("aria-invalid", "true");
    } else {
      control.removeAttribute("aria-invalid");
    }
  }
}

function controlFor(form, config) {
  return form.querySelector(`[name="${CSS.escape(config.name)}"]`);
}

function fieldError(form, config, mode) {
  if (config.type === "multiselect") {
    return "";
  }
  const control = controlFor(form, config);
  if (!control) {
    return "";
  }
  const raw = String(control.value || "").trim();
  if (raw === "") {
    if (!isRequired(config, mode) || config.validate === false) {
      return "";
    }
    const picks = config.type === "select" || config.type === "remote-select" || config.type === "remote-multiselect";
    return t(picks ? "form.select" : "form.required", { label: config.label });
  }
  if (config.type === "number" && !Number.isFinite(Number(raw))) {
    return t("form.number", { label: config.label });
  }
  if (config.type === "json") {
    try {
      JSON.parse(raw);
    } catch (error) {
      return t("form.json", { label: config.label, message: error.message });
    }
  }
  return "";
}

function validateControl(wrapper, config, mode) {
  const form = wrapper.closest("form") || wrapper;
  setFieldError(wrapper, fieldError(form, config, mode));
}

/** Validates fields, marks invalid ones and returns the list of errors. */
export function validateFields(form, fields, { mode = "create" } = {}) {
  const errors = [];
  for (const config of fields) {
    const message = fieldError(form, config, mode);
    const wrapper = form.querySelector(`[data-field="${CSS.escape(config.name)}"]`);
    setFieldError(wrapper, message);
    if (message) {
      errors.push({ name: config.name, message });
    }
  }
  if (errors.length > 0) {
    const first = form.querySelector(`[data-field="${CSS.escape(errors[0].name)}"]`);
    first?.querySelector("input:not([type=hidden]), select, textarea")?.focus();
  }
  return errors;
}

export class FormValidationError extends Error {
  constructor(errors) {
    super(t("form.fixErrors"));
    this.code = "FORM_INVALID";
    this.errors = errors;
  }
}

/**
 * Reads form values. With { validate: true } the fields are validated first
 * (required, number, JSON) and a FormValidationError is thrown when any fails.
 */
export function readFields(form, fields, { validate = false, mode = "create" } = {}) {
  if (validate) {
    const errors = validateFields(form, fields, { mode });
    if (errors.length > 0) {
      throw new FormValidationError(errors);
    }
  }
  const values = {};
  for (const config of fields) {
    if (config.type === "multiselect") {
      const selected = [...form.querySelectorAll(`input[type="checkbox"][name="${config.name}"]:checked`)]
        .map((input) => input.value);
      if (config.optional && selected.length === 0) {
        continue;
      }
      values[config.name] = config.coerce === "number" ? selected.map(Number).filter((value) => Number.isFinite(value)) : selected;
      continue;
    }
    const control = form.querySelector(`[name="${config.name}"]`);
    if (!control) {
      continue;
    }
    const raw = control.value.trim();
    if (config.type === "remote-multiselect") {
      const items = raw === "" ? [] : raw.split(",").map((item) => item.trim()).filter(Boolean);
      if (config.optional && items.length === 0) {
        continue;
      }
      values[config.name] = config.coerce === "number" ? items.map(Number).filter((value) => Number.isFinite(value)) : items;
      continue;
    }
    if (config.type === "cidr-list") {
      values[config.name] = raw === ""
        ? []
        : raw.split(/[\n,]+/).map((item) => item.trim()).filter(Boolean);
      continue;
    }
    if (config.optional && raw === "") {
      continue;
    }
    if (config.type === "number" || config.coerce === "number") {
      values[config.name] = raw === "" ? null : Number(raw);
    } else if (config.type === "json") {
      values[config.name] = raw === "" ? null : JSON.parse(raw);
    } else {
      values[config.name] = raw;
    }
  }
  return values;
}

/** Opens (replacing any open dialog) a modal with the given body. Returns the dialog handle. */
export function showModal(title, body, options = {}) {
  return openModal({ title, body, ...options });
}

export function statusMessage(text, tone = "") {
  return el("p", { className: `notice ${tone}`.trim(), text, role: tone === "error" ? "alert" : null });
}
