# System Settings Center Design

## Goal

Upgrade TokenHub settings from a generic JSON table into a typed settings center that covers certificate alerts, entry security, retention, export, backup, logs, and alert thresholds.

## Scope

- Add a super-admin-only settings center in the admin console.
- Keep the existing `system-settings` resource as an advanced fallback.
- Store typed settings in `system_settings` with stable keys and default values.
- Reuse existing services where they already own behavior, especially content retention, log retention, and certificate alert evaluation.
- Audit every settings update.

## Settings Sections

### Certificates And Entry

The section stores TLS certificate alert inputs under `tls_certificates`. It includes a certificate list with `name`, `domains`, `issuer`, `expires_at`, and `status`, plus `threshold_days`. The existing `AlertService` reads this key and can emit certificate-expiring alerts.

The section also stores entry access settings under `entry_security`, including admin allowed CIDRs, API allowed CIDRs, whether API Key CIDR checks are expected to be enforced, and sensitive header/query field names for masking.

### Retention And Export

The section stores export and backup defaults under `retention_export`. It includes export download URL TTL, export file retention days, backup retention days, and cleanup batch size. Content retention remains on the existing dedicated `/admin-api/system/content-retention` endpoint.

### Alerts

The section stores operational thresholds under `alert_thresholds`. It includes WAF spike threshold/window, provider error rate threshold/window/minimum request count, queue backlog threshold, and certificate threshold mirroring.

### Runtime Summary

The settings center returns a read-only summary containing configuration completeness, existing log retention policy count, open alert count, and last alert records. It does not mutate runtime systems.

## Backend Design

Create `TokenHub\Services\SystemSettingsService`.

Responsibilities:

- Define allowed section keys, defaults, and normalization rules.
- Load settings from `system_settings`.
- Save one section at a time.
- Reject unknown sections.
- Write audit records using section-specific actions.
- Return a settings-center payload with sections and summary.
- Trigger certificate alert evaluation on demand.

Admin routes:

```text
GET  /admin-api/system/settings-center
PUT  /admin-api/system/settings-center/{section}
POST /admin-api/system/settings-center/certificate-alert-check
```

All write routes require admin auth and CSRF. The service also requires the actor role to be `super_admin`.

## Frontend Design

Add a dedicated `settings-center` admin resource in the System group. The page uses static JavaScript modules only, matching the current admin architecture.

The page renders:

- Certificates and entry security forms.
- Retention/export form.
- Alert threshold form.
- Runtime summary panel.
- Certificate alert check action.

Forms use normal inputs and textareas for list values. JSON editing is limited to arrays where the shape is simple and validated by the backend.

## Testing

Backend tests cover:

- Defaults for all settings center sections.
- Normalization and clamping of invalid values.
- Unknown section rejection.
- Certificate alert check returns evaluated events.
- Super-admin route metadata exists in the router/controller source.

Frontend contract tests cover:

- `settings-center` resource visibility for `super_admin`.
- Dedicated renderer presence.
- Calls to the three settings-center endpoints.
- No `localStorage`, `sessionStorage`, `console`, `innerHTML`, or remote assets.

## Out Of Scope

- Generating or reloading Caddy TLS configuration.
- Certificate issuance through ACME or DNS provider APIs.
- Versioned settings rollback.
- Approval workflows for configuration changes.
