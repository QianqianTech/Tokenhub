# Advanced Settings Clarity Design

## Goal

Make the advanced-settings entry understandable and safe for administrators by separating custom configuration from the product settings that already have dedicated, typed forms.

## Scope

- Keep the existing `system-settings` route and its create/edit capability for custom settings.
- Rename the page and all related controls to make its technical, custom-only purpose explicit.
- Hide managed settings-center sections, the content-retention policy, and demo seed markers from the custom-settings list.
- Explain that normal product configuration belongs in the Settings Center.
- Show a clear empty state when no custom settings exist.

## User Experience

The page heading becomes `自定义系统配置` with a short explanation: this area is for technical staff to create application-specific extension settings, and the normal settings such as branding, certificates, security, RAG, retention, and alerts are managed in `设置中心`.

Only user-defined settings remain as editable cards. Cards retain their description and typed-field editor, but no managed key, operational state, or demo-only marker is shown. The add action becomes `新增自定义配置`.

If there are no custom settings, the page explains that no action is required for normal administration and directs the user to `设置中心` for product configuration.

## Data Flow And Safety

The frontend keeps loading the existing `/admin-api/system-settings` resource. A local allowlist of managed keys filters entries before rendering, so backend contracts and stored values remain unchanged. Filtering is presentation-only: custom configuration creation and editing keep their current API behavior.

## Testing

- Add a frontend contract test proving managed sections, the content-retention policy, and demo markers are not rendered as custom configuration.
- Verify the page contains the new purpose copy, custom-only title, and the Settings Center guidance.
- Run the focused frontend test and the existing settings-center/frontend security contract tests.
