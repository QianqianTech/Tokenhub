# Admin Sidebar Search Removal Design

## Goal

Remove the admin sidebar's menu-search surface and every interaction dedicated to it. Keep the existing role-filtered navigation, section toggles, recent pages, compact sidebar mode, and mobile drawer intact.

## Scope

- Remove the sidebar search label and input.
- Remove query state and query-based menu filtering.
- Remove the Ctrl/Cmd+K shortcut and Escape handling that clears or closes search.
- Remove compact-mode search state and its compact-mode expansion behavior.
- Remove search-only CSS rules and update frontend contract assertions so they describe the remaining sidebar behavior.

## Design

`app.js` will render the sidebar directly with its recent-pages region and navigation-list host. The sidebar state will retain only state used by remaining features. Navigation sections will no longer be filtered by a user query or temporarily opened because of a match. Keyboard handling will continue to support any non-search sidebar controls, including closing the mobile drawer.

The existing collapsible, permission-filtered section navigation remains the sole route-discovery mechanism. No API, resource metadata, authentication, or server-side behavior changes.

## Verification

Update the focused static frontend test to require that search-specific renderer, state, keyboard shortcut, and CSS hooks are absent while preserving assertions for navigation sections and toggles. Run JavaScript syntax validation for the changed script and the focused Docker PHP frontend test.
