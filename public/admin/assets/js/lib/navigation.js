export function ragNavigate(page, filters = {}) {
  window.dispatchEvent(new CustomEvent("tokenhub-admin-navigate", { detail: { page, filters } }));
}
