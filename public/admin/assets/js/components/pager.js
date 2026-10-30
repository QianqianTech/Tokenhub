import { button, el } from "../dom.js";
import { t } from "../i18n.js";

export const PAGE_SIZES = [20, 50, 100];

// Page numbers to show: first, last and a window around the current page,
// with gaps marked as null (rendered as “…”).
function pageWindow(page, pages) {
  if (pages <= 7) {
    return Array.from({ length: pages }, (_, index) => index + 1);
  }
  const set = new Set([1, pages, page - 1, page, page + 1]);
  if (page <= 3) [2, 3, 4].forEach((item) => set.add(item));
  if (page >= pages - 2) [pages - 3, pages - 2, pages - 1].forEach((item) => set.add(item));
  const sorted = [...set].filter((item) => item >= 1 && item <= pages).sort((a, b) => a - b);
  const result = [];
  sorted.forEach((item, index) => {
    if (index > 0 && item - sorted[index - 1] > 1) {
      result.push(null);
    }
    result.push(item);
  });
  return result;
}

/**
 * Pagination control. When `total` is unknown (null) the pager still allows
 * moving forward while the current page is full.
 */
export function pager({ total = null, limit, offset, count, onChange, pageSizes = PAGE_SIZES, label = t("pager.label") }) {
  const page = Math.floor(offset / limit) + 1;
  const known = Number.isFinite(total);
  const pages = known ? Math.max(1, Math.ceil(total / limit)) : null;
  const hasNext = known ? offset + limit < total : count >= limit;
  const go = (nextOffset, nextLimit = limit) => onChange({ offset: Math.max(0, nextOffset), limit: nextLimit });

  const previous = button(t("pager.previous"), () => go(offset - limit), "secondary pager-step", { disabled: offset <= 0 });
  const next = button(t("pager.next"), () => go(offset + limit), "secondary pager-step", { disabled: !hasNext });
  const size = el("select", { "aria-label": "每页条数" }, pageSizes.map((value) => el("option", { value: String(value), text: `${value} 条`, selected: value === limit })));
  size.addEventListener("change", () => go(0, Number(size.value)));

  const actions = [previous];
  if (known && pages > 1) {
    for (const item of pageWindow(page, pages)) {
      if (item === null) {
        actions.push(el("span", { className: "pager-gap", text: "…", "aria-hidden": "true" }));
        continue;
      }
      actions.push(el("button", {
        type: "button",
        className: `pager-page ${item === page ? "is-current" : ""}`.trim(),
        text: String(item),
        "aria-label": `第 ${item} 页`,
        "aria-current": item === page ? "page" : null,
        onClick: () => {
          if (item !== page) go((item - 1) * limit);
        }
      }));
    }
  }
  actions.push(next, el("label", { className: "pager-size" }, [el("span", { text: t("pager.pageSize") }), size]));

  const from = count > 0 ? offset + 1 : 0;
  const to = offset + count;
  return el("nav", { className: "pager", "aria-label": label }, [
    el("span", {
      className: "pager-summary",
      "aria-live": "polite",
      text: known ? t("pager.summary", { total, from, to, page, pages }) : t("pager.summaryUnknown", { page, count })
    }),
    el("div", { className: "pager-actions" }, actions)
  ]);
}
