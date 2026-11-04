// In-app navigation from page modules: the admin shell (and the APP shell)
// listen for this event, check the permission and update the hash route.
export function navigateTo(page, filters = {}) {
  window.dispatchEvent(new CustomEvent("tokenhub-admin-navigate", { detail: { page, filters } }));
}

// Kept for the RAG pages that already import it.
export const ragNavigate = navigateTo;
