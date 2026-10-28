# Admin Semantic Theme Switcher Design

## Goal

Replace the admin console's opaque A/B/C visual-style selector with explicit Deep, Light, and Auto theme choices. Auto follows the operating system color preference and updates while the console is open.

## Scope

- Change the three top-bar choices to the Chinese labels `深色`, `浅色`, and `自适应`.
- Persist the semantic choice in the existing `tokenhub_admin_theme` cookie.
- Use `prefers-color-scheme` for the Auto choice and respond to system preference changes.
- Retain the current light and dark visual token sets; remove the compact-density A/B distinction.
- Migrate legacy cookie values without a broken initial paint: `a` and `b` become light; `c` becomes dark.
- Update the focused admin frontend contract test.

## Non-goals

- No backend endpoint, settings API, or database change.
- No change to sidebar/navigation layout, page content, or the separate screen display.
- No user-configurable custom palette.

## Design

The markup exposes `data-theme-choice="dark"`, `data-theme-choice="light"`, and `data-theme-choice="auto"`, using descriptive Chinese labels and accessible titles. The stored preference remains separate from the resolved palette: the selected control can be `auto`, while the root element receives either `theme-dark` or `theme-light` according to the current system preference.

JavaScript normalizes both semantic values and legacy A/B/C values. It listens to the `prefers-color-scheme: dark` media query only when Auto is selected; when a user selects Deep or Light, the same listener no longer changes the active palette. A selection is saved in the existing same-site cookie and all controls keep their correct `aria-pressed` state.

CSS defines semantic `theme-light` and `theme-dark` variables. Shared component styling continues to consume those variables, so changing theme does not alter the layout. The existing dark-specific component overrides are renamed to target `theme-dark`.

## Verification

- Add a frontend contract assertion for the three semantic theme values and Chinese labels.
- Assert the JavaScript contains semantic normalization plus `matchMedia` system-preference handling.
- Assert the stylesheet defines semantic light and dark theme selectors, and no longer depends on the A/B/C selectors.
- Run `node --check public/admin/assets/js/app.js`.
- Run `docker compose exec -T php php tests/Frontend/AdminFrontendSecurityTest.php` using the container's Unix-style path.
