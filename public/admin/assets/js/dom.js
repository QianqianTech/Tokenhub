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

export function button(label, onClick, variant = "", attrs = {}) {
  return el("button", { type: "button", className: variant, text: label, onClick, ...attrs });
}

export function field(config, value = "") {
  const isMultiselect = config.type === "multiselect";
  const wrapper = el(isMultiselect ? "div" : "label", { className: "field" });
  wrapper.append(el("span", { text: config.label }));
  let control;
  if (config.type === "select") {
    control = el("select", { name: config.name });
    for (const [optionValue, optionLabel] of config.options || []) {
      control.append(el("option", { value: optionValue, text: optionLabel }));
    }
  } else if (isMultiselect) {
    let selectedValues = Array.isArray(value) ? value : [];
    if (!Array.isArray(value) && value != null && String(value).trim() !== "") {
      try {
        const parsed = JSON.parse(String(value));
        selectedValues = Array.isArray(parsed) ? parsed : String(value).split(",");
      } catch {
        selectedValues = String(value).split(",");
      }
    }
    const selected = new Set(selectedValues.map((item) => String(item).trim()).filter(Boolean));
    const list = el("div", {
      className: "multiselect-list",
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
      button("全选", () => {
        list.querySelectorAll('input[type="checkbox"]').forEach((input) => {
          input.checked = true;
        });
      }, "secondary"),
      button("清空", () => {
        list.querySelectorAll('input[type="checkbox"]').forEach((input) => {
          input.checked = false;
        });
      }, "secondary")
    ]);
    control = el("div", { className: "multiselect-field" }, [toolbar, list]);
  } else if (config.type === "textarea" || config.type === "json" || config.type === "cidr-list") {
    control = el("textarea", { name: config.name, rows: config.rows || 3 });
  } else {
    control = el("input", { name: config.name, type: config.type || "text" });
  }
  if ("autocomplete" in control) {
    control.autocomplete = "off";
  }
  if (!isMultiselect) {
    control.value = value == null ? "" : String(value);
  }
  wrapper.append(control);
  return wrapper;
}

export function readFields(form, fields) {
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

export function formatValue(value) {
  if (value === null || value === undefined) {
    return "";
  }
  if (typeof value === "object") {
    return JSON.stringify(value);
  }
  return String(value);
}

export function showModal(title, body) {
  const root = $("modal-root");
  clear(root);
  const close = button("关闭", () => clear(root), "secondary");
  const dialog = el("section", { className: "modal-dialog" }, [
    el("header", { className: "modal-header" }, [
      el("h2", { text: title }),
      close
    ]),
    el("div", { className: "modal-body" }, [body])
  ]);
  root.append(el("div", { className: "modal-backdrop", onClick: () => clear(root) }), dialog);
}

export function statusMessage(text, tone = "") {
  return el("p", { className: `notice ${tone}`.trim(), text });
}
