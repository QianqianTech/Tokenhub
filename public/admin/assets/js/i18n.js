import * as zhCN from "./i18n/zh-CN.js";
import * as enUS from "./i18n/en-US.js";

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
  "zh-CN": flatten(zhCN),
  "en-US": flatten(enUS)
};

const defaultLocale = "zh-CN";
const languageCookie = "tokenhub_locale";
const sitePath = globalThis.location?.pathname.match(/^\/(admin|app|screen)(?=\/|$)/)?.[0];

function savedLocale() {
  const prefix = `${languageCookie}=`;
  const saved = globalThis.document?.cookie.split(";").map((item) => item.trim())
    .find((item) => item.startsWith(prefix))?.slice(prefix.length);
  return Object.hasOwn(messages, saved) ? saved : null;
}

function preferredLocale() {
  const requested = globalThis.location && new URLSearchParams(location.search).get("lang");
  return savedLocale() ?? (Object.hasOwn(messages, requested) ? requested : defaultLocale);
}

let locale = preferredLocale();
export const initialLocale = locale;

export function getLocale() {
  return locale;
}

export function setLocale(next) {
  if (Object.hasOwn(messages, next)) {
    locale = next;
  }
}

function lookup(key) {
  return messages[locale][key] ?? messages[defaultLocale][key];
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

// Existing page copy is looked up by its Chinese source while shared controls
// use stable keys. Only mark product-authored strings for translation.
export function localize(source, ...values) {
  if (typeof source === "string") {
    return locale === "en-US" ? enUS.copy[source] ?? source : source;
  }
  const key = source.reduce((result, part, i) => i === 0 ? part : `${result}{${i - 1}}${part}`, "");
  return localize(key).replace(/\{(\d+)\}/g, (match, index) => index < values.length ? String(values[index]) : match);
}

const originalText = new WeakMap();
const translatedAttributes = ["aria-label", "title", "placeholder", "alt", "aria-description"];

function localizeDocument() {
  document.documentElement.lang = locale;
  for (const node of document.querySelectorAll("[data-localize]")) {
    if (!originalText.has(node)) {
      originalText.set(node, {
        text: node.getAttribute("data-localize") === "text" ? node.textContent : null,
        attributes: translatedAttributes.filter((name) => node.hasAttribute(name))
          .map((name) => [name, node.getAttribute(name)])
      });
    }
    const { text, attributes } = originalText.get(node);
    if (text !== null) node.textContent = localize(text);
    for (const [name, value] of attributes) node.setAttribute(name, localize(value));
  }
}

function saveLocale(next) {
  const secure = location.protocol === "https:" ? "; Secure" : "";
  document.cookie = `${languageCookie}=${next}; Max-Age=31536000; Path=${sitePath}; SameSite=Lax${secure}`;
  return savedLocale() === next;
}

export function initLocaleControls({ beforeChange = () => true, applyInPlace = () => false, onChange = () => {} } = {}) {
  if (sitePath && !savedLocale() && locale !== defaultLocale) saveLocale(locale);
  const controls = [...document.querySelectorAll("[data-locale-picker]")].map((host) => {
    const select = document.createElement("select");
    select.setAttribute("aria-label", "界面语言");
    select.setAttribute("data-localize", "");
    select.append(new Option("简体中文", "zh-CN"), new Option("English", "en-US"));
    select.options[0].lang = "zh-CN";
    select.options[1].lang = "en-US";
    host.append(select);
    return select;
  });
  localizeDocument();
  for (const select of controls) {
    select.value = locale;
    select.addEventListener("change", async () => {
      const next = select.value;
      if (next === locale) return;
      select.value = locale;
      if (!sitePath || !Object.hasOwn(messages, next) || !(await beforeChange(next))) return;

      if (!saveLocale(next)) return;
      if (!applyInPlace()) {
        location.reload();
        return;
      }
      setLocale(next);
      localizeDocument();
      for (const control of controls) control.value = locale;
      onChange();
    });
  }
}
