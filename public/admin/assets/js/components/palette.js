// Command palette (Ctrl/⌘ + K): jump to any page the user may open by typing
// part of its name, its domain, or the initials of the Chinese name.
import { el } from "../dom.js";
import { t } from "../i18n.js";
import { openModal } from "./modal.js";

let entries = () => [];
let navigateTo = () => {};
let openHandle = null;

function normalize(text) {
  return String(text || "").toLowerCase().replace(/\s+/g, "");
}

function score(entry, query) {
  if (!query) {
    return 1;
  }
  const haystacks = [entry.label, entry.domain, entry.key, ...(entry.keywords || [])].map(normalize);
  let best = 0;
  for (const text of haystacks) {
    if (text === query) best = Math.max(best, 100);
    else if (text.startsWith(query)) best = Math.max(best, 60);
    else if (text.includes(query)) best = Math.max(best, 40);
    else {
      // subsequence match, e.g. "apimy" -> "api 密钥"
      let index = 0;
      for (const char of text) {
        if (char === query[index]) index += 1;
        if (index === query.length) break;
      }
      if (index === query.length) best = Math.max(best, 10);
    }
  }
  return best;
}

export function configurePalette({ getEntries, navigate }) {
  entries = getEntries;
  navigateTo = navigate;
}

export function openPalette() {
  if (openHandle) {
    return;
  }
  const input = el("input", {
    type: "search",
    className: "palette-input",
    placeholder: t("palette.placeholder"),
    role: "combobox",
    "aria-expanded": "true",
    "aria-controls": "palette-list",
    "aria-autocomplete": "list",
    autocomplete: "off"
  });
  const list = el("div", { id: "palette-list", className: "palette-list", role: "listbox" });
  let active = 0;
  let results = [];

  const render = () => {
    const query = normalize(input.value);
    results = entries()
      .map((entry) => ({ entry, score: score(entry, query) }))
      .filter((item) => item.score > 0)
      .sort((a, b) => b.score - a.score)
      .slice(0, 30)
      .map((item) => item.entry);
    active = Math.min(active, Math.max(0, results.length - 1));
    list.replaceChildren(...(results.length
      ? results.map((entry, index) => el("a", {
        id: `palette-option-${index}`,
        className: `palette-item ${index === active ? "is-active" : ""}`.trim(),
        href: entry.href,
        role: "option",
        "aria-selected": index === active ? "true" : "false",
        onClick: (event) => {
          event.preventDefault();
          choose(entry);
        }
      }, [el("span", { text: entry.label }), el("small", { text: entry.domain })]))
      : [el("p", { className: "remote-select-message", text: t("palette.empty") })]));
    input.setAttribute("aria-activedescendant", results.length ? `palette-option-${active}` : "");
  };

  const choose = (entry) => {
    void openHandle?.close({ force: true });
    navigateTo(entry.key);
  };

  input.addEventListener("input", () => {
    active = 0;
    render();
  });
  input.addEventListener("keydown", (event) => {
    if (event.key === "ArrowDown" || event.key === "ArrowUp") {
      event.preventDefault();
      if (results.length) {
        active = (active + (event.key === "ArrowDown" ? 1 : -1) + results.length) % results.length;
        render();
        list.querySelector(".is-active")?.scrollIntoView({ block: "nearest" });
      }
    } else if (event.key === "Enter") {
      event.preventDefault();
      if (results[active]) {
        choose(results[active]);
      }
    }
  });

  const body = el("div", { className: "palette" }, [input, list, el("p", { className: "palette-hint", text: t("palette.hint") })]);
  openHandle = openModal({
    title: t("palette.title"),
    body,
    size: "sm",
    guardDirty: false,
    stack: true,
    className: "modal-dialog--palette",
    onClose: () => {
      openHandle = null;
    }
  });
  render();
  input.focus();
}

export function bindPaletteShortcut() {
  document.addEventListener("keydown", (event) => {
    if ((event.ctrlKey || event.metaKey) && !event.altKey && String(event.key).toLowerCase() === "k") {
      event.preventDefault();
      openPalette();
    }
  });
}
