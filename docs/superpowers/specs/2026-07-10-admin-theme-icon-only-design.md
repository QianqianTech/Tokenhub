# Admin Theme Icon-Only Switcher Design

## Goal

Render the existing admin theme choices as three icon-only controls: dark, light, and automatic.

## Scope

- Keep the three existing choices and their `data-theme-choice` values: `dark`, `light`, and `auto`.
- Replace the visible Chinese button labels with local inline SVG icons: moon, sun, and split-display.
- Keep exactly three visible theme icons; do not add a current-theme text label.
- Preserve `title`, `aria-label`, `aria-pressed`, keyboard operation, and focus styles.
- Keep the selected icon visually distinct with the existing primary-color active treatment.
- Retain the existing cookie persistence, legacy cookie migration, and system color-scheme handling.

## Non-goals

- No changes to theme colors, theme resolution, backend APIs, cookies, or admin layout beyond the switcher width and icon sizing.
- No external icon font, image, SVG file, or other network asset.

## Design

`public/admin/index.html` continues to own the static switcher markup. Each button contains one inline SVG and no visible text node. The moon, sun, and split-display glyphs use `currentColor`, so the existing active and hover colors apply without JavaScript changes.

`public/admin/assets/css/app.css` makes the choice controls square and compact, removes label-oriented minimum widths and horizontal padding, and preserves the current selected, hover, focus, and responsive switcher behavior.

`public/admin/assets/js/app.js` remains unchanged: it already uses stable `data-theme-choice` values and updates `aria-pressed` plus the active class. As a result, persistence and Auto behavior retain their existing contract.

## Verification

- Update the focused frontend contract test to require the three local inline SVG controls and accessible Chinese `title` / `aria-label` values.
- Assert the old visible labels are no longer button content and that the choice CSS is compact and icon-sized.
- Continue asserting semantic theme data values, cookie persistence, legacy migration, and system-preference handling.
- Run `node --check public/admin/assets/js/app.js` and `docker compose exec -T php php tests/Frontend/AdminFrontendSecurityTest.php`.

## Self-review

The scope is limited to the admin shell markup, its theme-control CSS, and its existing frontend contract test. It introduces no new data flow, assets, endpoints, or behavior beyond presentation and accessibility-preserving semantics.
