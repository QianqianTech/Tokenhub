import { button, el } from "../dom.js";
import { t } from "../i18n.js";

export const PAGE_SIZES = [20, 50, 100];

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

  const previous = button(t("pager.previous"), () => go(offset - limit), "secondary", { disabled: offset <= 0 });
  const next = button(t("pager.next"), () => go(offset + limit), "secondary", { disabled: !hasNext });
  const size = el("select", { "aria-label": t("pager.pageSize") }, pageSizes.map((value) => el("option", { value: String(value), text: String(value), selected: value === limit })));
  size.addEventListener("change", () => go(0, Number(size.value)));

  const actions = [previous];
  if (known && pages > 2) {
    const jump = el("input", { type: "number", className: "pager-jump", min: "1", max: String(pages), value: String(page), "aria-label": "跳转到页" });
    jump.addEventListener("change", () => {
      const target = Math.min(pages, Math.max(1, Number(jump.value) || 1));
      go((target - 1) * limit);
    });
    actions.push(jump);
  }
  actions.push(next, el("label", { className: "pager-size" }, [el("span", { text: t("pager.pageSize") }), size]));

  return el("nav", { className: "pager", "aria-label": label }, [
    el("span", {
      className: "pager-summary",
      "aria-live": "polite",
      text: known ? t("pager.summary", { total, page, pages }) : t("pager.summaryUnknown", { page, count })
    }),
    el("div", { className: "pager-actions" }, actions)
  ]);
}
