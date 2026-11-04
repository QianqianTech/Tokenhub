// Translation lookup for the admin UI: the strings live in per-locale
// dictionaries under i18n/ (zh-CN.js): shell/component strings, column labels,
// enum value labels, list page descriptions and API error messages. New shared
// copy should be added there so wording stays consistent and can be
// customised per deployment in one place.
import * as zhCN from "./i18n/zh-CN.js";

// { ui, columns, values, pageDescriptions, errors } -> flat "key": "text" map.
function flatten(dictionary) {
  const flat = { ...dictionary.ui };
  for (const [column, label] of Object.entries(dictionary.columns || {})) {
    flat[`column.${column}`] = label;
  }
  for (const [column, labels] of Object.entries(dictionary.values || {})) {
    for (const [value, label] of Object.entries(labels)) {
      flat[`value.${column}.${value}`] = label;
    }
  }
  for (const [page, description] of Object.entries(dictionary.pageDescriptions || {})) {
    flat[`page.${page}.description`] = description;
  }
  for (const [code, message] of Object.entries(dictionary.errors || {})) {
    flat[`error.${code}`] = message;
  }
  return flat;
}

const messages = {
  "zh-CN": flatten(zhCN)
};

let locale = "zh-CN";

export function setLocale(next) {
  if (messages[next]) {
    locale = next;
  }
}

function lookup(key) {
  return messages[locale]?.[key] ?? messages["zh-CN"][key];
}

/** The text for a key, or `fallback` when the dictionary has none. */
export function translate(key, fallback = key) {
  const text = lookup(key);
  return text === undefined ? fallback : text;
}

export function t(key, params = {}) {
  const template = lookup(key) ?? key;
  return template.replace(/\{(\w+)\}/g, (_, name) => (params[name] === undefined ? `{${name}}` : String(params[name])));
}
