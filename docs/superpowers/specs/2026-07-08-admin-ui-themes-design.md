# Admin UI Themes Design

## Goal

Make the TokenHub interfaces cleaner and faster without external links, remote assets, build tooling, or heavy runtime dependencies.

## Scope

- Add an A/B/C theme selector to the admin console.
- Keep the selector local to static admin assets.
- Persist the non-sensitive theme choice without `localStorage` or `sessionStorage`.
- Lightly polish the screen view with local CSS only.

## Theme Directions

- A: clean operations console. This remains the default.
- B: minimal dense table tool. This reduces spacing for data-heavy workflows.
- C: dark display style. This lets the admin console match the screen view when needed.

## Implementation

The admin root uses theme classes on `#app`: `theme-a`, `theme-b`, and `theme-c`. The selector is a compact segmented control in the top bar. JavaScript applies the selected class, marks the active button, and stores the choice in a simple same-site cookie named `tokenhub_admin_theme`.

The screen page keeps its existing standalone flow and receives CSS-only polish. No external fonts, images, icon CDNs, or network assets are introduced.

## Verification

Frontend contract tests must assert the theme selector, theme classes, cookie persistence, and the no-external-assets constraint. Browser verification should cover admin and screen pages at desktop and mobile widths when possible.
