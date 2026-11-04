// On phones, console tables inside the APP become cards: every cell gets its
// column title as a label (data-label) and CSS stacks the cells. Tables are
// labelled again whenever a page re-renders rows (paging, filters).
const NARROW = window.matchMedia("(max-width: 720px)");

function label(table) {
  const heads = [...table.querySelectorAll(":scope > thead > tr > th")].map((th) => th.textContent.replace(/\s+/g, " ").trim());
  if (heads.length === 0) {
    return;
  }
  table.classList.add("tha-cards");
  for (const row of table.querySelectorAll(":scope > tbody > tr")) {
    [...row.children].forEach((cell, index) => {
      if (cell.tagName === "TD" && !cell.hasAttribute("data-label")) {
        cell.setAttribute("data-label", heads[index] || "");
        if (cell.colSpan > 1) {
          cell.classList.add("tha-card-wide");
        }
      }
    });
  }
}

/** Starts labelling tables under root; returns a stop function. */
export function enableCardTables(root) {
  let scheduled = 0;
  const run = () => {
    scheduled = 0;
    root.querySelectorAll("table").forEach(label);
  };
  const observer = new MutationObserver(() => {
    if (!scheduled) {
      scheduled = window.requestAnimationFrame(run);
    }
  });
  observer.observe(root, { childList: true, subtree: true });
  run();
  root.classList.toggle("tha-narrow", NARROW.matches);
  const onChange = () => root.classList.toggle("tha-narrow", NARROW.matches);
  NARROW.addEventListener?.("change", onChange);
  return () => {
    observer.disconnect();
    NARROW.removeEventListener?.("change", onChange);
  };
}
