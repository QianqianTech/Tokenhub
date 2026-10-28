# TokenHub demo data design

## Goal

Add comprehensive demo data so the Web admin console looks like a real TokenHub deployment after an operator explicitly seeds a local or demo database.

The seed must cover every visible admin module, preserve production safety, and be repeatable. It must not run automatically as part of normal migrations.

## Approach

Create a dedicated `scripts/seed-demo.php` command that uses the existing bootstrap container and `TokenHub\Support\Database` connection. The command will insert or update records by stable demo identifiers, then print a concise summary of created or refreshed records.

This is safer than embedding data in migrations because migrations are production lifecycle code. Demo data is environment-specific and should be opt-in.

## Scope

The script will seed these functional areas:

- Organization: departments with a small hierarchy, active users across `super_admin`, `admin`, and `user` roles, realistic `last_login_at`, and scoped `managed_department_ids`.
- Providers and models: OpenAI-compatible, Anthropic-compatible, and native multimodal providers; text, vision, embedding, ASR, TTS, image, and video models; model prices for token and media metrics.
- Access control: API keys with safe fake hashes, prefixes, suffixes, expiry dates, allowed IP ranges, and model permission bindings.
- Quotas: department, user, and API-key quota policies and bindings.
- Usage: recent usage records across success, failed, blocked, and timeout states; matching content-retention rows with summaries and redacted samples.
- Aggregates: minute, hour, and day aggregate rows for the last 48 hours and current day so dashboard realtime, trends, rankings, performance, and multimodal panels are populated immediately.
- Multimodal: task rows for ASR, TTS, image, and video workflows plus media asset rows with realistic sizes, MIME types, and expiry dates.
- Operations: export tasks, log archive files, alert events, audit logs, system settings, and content retention policy.
- Security: WAF rules, WAF versions, WAF events, desensitization policies, and desensitization events.
- Big screen sharing: dashboard share links and access logs for successful login, denied IP, expired session, and bad-password examples.

## Idempotency

The script will use stable demo keys and unique fields:

- Departments by path/name under `/1/`.
- Users by username.
- Providers by provider name.
- Models by provider plus model name.
- API keys by deterministic `key_hash`.
- Usage records by deterministic `request_id`.
- Tasks/assets/exports/share links/rules by their natural unique codes.

Existing demo rows will be updated when practical. Rows not clearly owned by the demo seed will not be deleted or overwritten.

## Data Quality

Demo data should look realistic without containing real secrets or personal data:

- Use obviously fake API key prefixes and hashes.
- Use RFC 5737 documentation IP ranges where possible.
- Use non-sensitive Chinese business labels for departments and summaries.
- Use relative timestamps generated from current time so dashboards stay fresh whenever the script is rerun.
- Keep numeric metrics internally consistent: token totals equal input plus output, aggregate counts reflect seeded usage patterns, and failed counts match statuses.

## Implementation Notes

The command should prefer small helper functions for:

- Fetching IDs by SQL after upsert.
- JSON encoding with `JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES`.
- Timestamp generation in MySQL `Y-m-d H:i:s` format.
- Inserting aggregate rows into `usage_aggregates_minute`, `usage_aggregates_hour`, and `usage_aggregates_day`.
- Building deterministic fake hashes with `hash('sha256', ...)`.

The implementation should not depend on admin authentication services because it seeds below the HTTP layer.

## Tests

Add focused console or script tests that verify:

- `scripts/seed-demo.php` exists and refuses unexpected destructive modes.
- The script includes stable demo identifiers for all core modules.
- The script contains inserts or upserts for dashboard source tables, especially `usage_records`, `usage_aggregates_hour`, `usage_aggregates_day`, `waf_events`, `desensitization_events`, `audit_logs`, `multimodal_tasks`, and `dashboard_share_links`.

If a database is available, manually verify with:

```sh
docker compose exec -T php php bin/migrate.php
docker compose exec -T php php scripts/seed-demo.php
```

Then log into `/admin/` and check the dashboard plus every admin menu.

## Out of Scope

- No real provider credentials.
- No automatic production seeding.
- No UI redesign.
- No deletion of operator-created data.
