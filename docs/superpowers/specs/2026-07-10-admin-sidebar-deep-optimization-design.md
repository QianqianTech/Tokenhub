# Admin Sidebar Deep Optimization Design

## Goal

Turn the TokenHub admin sidebar into a reliable dual-state workspace navigation system. The change must fix the current interaction defects, remain efficient with a large permission-driven menu, and provide coherent desktop, narrow-screen, and mobile behavior without changing backend APIs or role permissions.

## Scope

The implementation changes only the admin shell and its frontend contracts:

- `public/admin/index.html`
- `public/admin/assets/js/resources.js`
- `public/admin/assets/js/app.js`
- `public/admin/assets/css/app.css`
- focused tests under `tests/Frontend/`

The implementation does not change admin APIs, authentication, role access, resource routes, database schema, or the settings center's in-page category and section tabs. It adds no frontend framework and loads no external assets.

## Current Problems

The current navigation renderer rebuilds the entire `<nav>` tree on every search input event. That destroys and recreates the focused search input after each character, so continuous typing is unreliable. Full navigation rebuilds also reset the sidebar's scroll position during page changes.

Open-section state is derived from a cookie, but the active section is forced open even when the user explicitly collapses it. The shell has only one desktop width and the mobile breakpoint places a tall sidebar above the workspace, pushing content down rather than presenting navigation as an overlay. Navigation accessibility is incomplete: active items do not expose `aria-current`, section toggles do not control named list regions, and there is no keyboard shortcut for search.

## Navigation Architecture

`resources.js` remains the source of truth for resource metadata, ordering, and role filtering. Navigation sections continue to be derived through `navSectionsForRole(role)`. Existing resource keys and access lists remain unchanged.

`app.js` owns a small sidebar state object alongside the existing admin state:

- display mode: `expanded` or `compact`;
- mobile drawer open state;
- search query;
- explicit open section keys;
- sidebar scroll position;
- recent resource keys.

Non-sensitive display preferences are stored in same-site cookies scoped to `/admin`. Ephemeral state such as the open mobile drawer is kept only in memory. Recent resources store only known frontend resource keys and are filtered against the current role before display.

Sidebar rendering is split into stable regions:

1. brand and mode toggle;
2. search control;
3. recent navigation shortcut;
4. independently scrollable section list;
5. current-user summary;
6. mobile backdrop and close affordance.

The search input is created once per authenticated shell session. Search changes update the section-list region in place while preserving focus, selection, and the sidebar scroll position. Page navigation updates active markers without rebuilding unrelated shell controls.

## Desktop Behavior

At viewport widths of 1024 pixels and above, the sidebar supports two persisted modes:

- Expanded mode uses a 240-pixel column and displays the brand, text labels, search field, recent item, section headings, counts, and resource labels.
- Compact mode uses a 56-pixel rail and displays local inline SVG icons for primary domains. Expanded mode renders every domain as a collapsible group with its permitted second-level pages underneath; content-area domain tabs remain a supplementary navigation surface. Each control has an accessible label and title. `Ctrl+K` opens or focuses navigation search; using it from compact mode temporarily opens an anchored search panel without permanently changing the stored mode.

The sidebar is sticky to the viewport and does not scroll with long page content. Only the section-list region scrolls. The current scroll position is restored after navigation and rerendering.

Section headers remain collapsible. Explicit user choice wins: the active page is always discoverable and receives active styling, but its section is not permanently locked open. When navigating to a page inside a collapsed section, that section opens for the navigation event; the user can collapse it again afterward.

## Search and Recent Navigation

Search matches normalized resource labels, resource keys, and section labels. Results update without recreating the input element. Matching sections open temporarily, while stored open-section preferences remain unchanged. `Escape` clears a non-empty query; a second `Escape` closes the compact search panel or mobile drawer.

The recent shortcut contains the most recently visited permitted resource other than the current page. It is omitted when no safe candidate exists. Only known resource keys are accepted from persisted state, and the role-filtered navigation model removes inaccessible entries.

## Mobile and Narrow-Screen Behavior

Between 821 and 1023 pixels, the shell defaults to the compact rail while still allowing the user to expand it when space permits.

At 820 pixels and below, the sidebar becomes an off-canvas drawer with a backdrop. It does not occupy document flow or push the workspace downward. A menu button in the top bar opens it. The drawer closes when the user selects a page, activates the close control, clicks the backdrop, or presses `Escape`. Focus returns to the menu button after closing.

The drawer uses a maximum width of 300 pixels and never exceeds 86 percent of the viewport. Body scroll is suppressed only while the drawer is open and restored reliably when it closes.

## Accessibility and Motion

- The navigation element has an explicit accessible label.
- Section buttons expose `aria-expanded` and `aria-controls` tied to stable list IDs.
- The active resource exposes `aria-current="page"`.
- Compact controls retain accessible names and visible tooltips or titles.
- The mobile menu button exposes `aria-expanded` and `aria-controls`.
- All controls have visible `:focus-visible` treatment.
- Transitions honor `prefers-reduced-motion: reduce`.
- Search results and the empty state are announced through a polite live region without announcing every unrelated rerender.

## State and Failure Handling

Cookie parsing treats missing, malformed, stale, or unknown values as safe defaults. Unknown section or resource keys are discarded against the current role-filtered navigation model. Cookie write failures do not block navigation; the UI continues with in-memory state.

If navigation metadata produces no permitted sections, the sidebar renders a clear empty state rather than an unusable blank rail. If the current page becomes inaccessible after an authentication or role-state change, existing app-level authorization behavior remains authoritative; this work does not introduce a new access path.

## Visual Design

The design reuses the existing theme variables for all three themes. Expanded and compact states share the same dark navigation surface, primary active color, muted labels, and control radius. The redesign reduces decorative counts and separators, strengthens active and focus states, and keeps Chinese labels readable without truncating the meaning of short items.

No icon font, SVG download, image URL, or other external asset is introduced. Compact icons are created locally with inline SVG paths, inherit the current theme color, and always retain accessible labels on their controlling buttons. One-character Chinese text badges are not used as icons.

## Testing

Implementation follows test-first development.

Focused frontend contracts cover:

- search updates without reconstructing the search input;
- explicit section collapse behavior, including the active section;
- expanded and compact mode persistence;
- role-filtered recent resources;
- scroll-position preservation hooks;
- mobile drawer controls and close behavior;
- accessibility attributes and reduced-motion styling;
- unchanged resource access lists and settings center navigation boundary;
- no external assets.

Verification includes JavaScript syntax checks, focused Docker PHP frontend tests, the complete frontend or project suite as justified by the touched shell surface, and browser checks at approximately 1440, 900, and 390 CSS pixels. Browser verification covers continuous search typing, page changes, section toggling, mode changes, drawer behavior, keyboard controls, focus return, and all three existing themes.

## Acceptance Criteria

- A user can continuously type a multi-character search query without losing focus.
- Searching, toggling sections, and changing pages do not unexpectedly reset sidebar scroll.
- The active section can be collapsed after navigation.
- Expanded, compact, and mobile states all expose the same role-permitted destinations.
- Desktop page content remains usable while long navigation lists scroll independently.
- Mobile navigation overlays the page and never pushes workspace content downward.
- Keyboard and screen-reader state accurately reflect active, expanded, and drawer states.
- Existing admin permissions, API contracts, settings center structure, themes, and local-only asset policy remain intact.
