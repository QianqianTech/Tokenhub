# Admin Sidebar Navigation Design

## Goal

Optimize the TokenHub admin sidebar for a large permission-driven menu while keeping the interface fast, local-only, and dependency-free.

## Scope

- Replace the flat grouped list with domain sections, a search box, counts, and collapsible groups.
- Add a "common entries" section for the workflows users open most often.
- Keep permissions as the source of truth: users only see resources allowed for their role.
- Fix the visible navigation labels and group names so the sidebar no longer shows mojibake text.
- Persist only non-sensitive sidebar open-section state in a same-site cookie.

## Navigation Model

The resource registry continues to own page metadata. A new `navigationSections` model defines sidebar order and grouping:

- Common: dashboard, usage records, logs.
- Operations: dashboard, screen shares, screen access logs.
- Organization: departments and users.
- Models: providers, models, prices.
- Access: API keys and quota controls.
- Usage: usage records, multimodal tasks, media assets.
- Security: WAF, desensitization, and security events.
- Logs: logs, archives, alerts, audits, content retention/export entries.
- System: retention policy and settings.

The renderer filters those sections by the current role's allowed resources.

## Interaction

The sidebar contains a compact search input under the brand. Search filters visible menu items and temporarily opens matched sections. Section headers are buttons with a count badge and an expand/collapse indicator. The current page's section is opened by default. Users can collapse or expand sections; open section keys are saved in `tokenhub_admin_nav_open`.

## Verification

Static frontend tests assert the navigation model, the renderer hooks, the no-storage security contract, the style hooks, and the no-external-assets constraint. Browser verification checks that the sidebar renders sections, search hides non-matching items, and toggling sections updates visibility.
