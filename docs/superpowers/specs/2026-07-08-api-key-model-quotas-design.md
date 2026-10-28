# API Key Model Quotas Design

## Goal

Add per-API-key, per-model quota controls. Empty values and `0` mean unlimited.

## Data Model

Create `api_key_model_quotas` with one row per `api_key_id`, `model_id`, and `period`.
The row can define request, input token, output token, and total token limits.
Rows with `status = active` are enforced. Missing rows, `NULL` limits, and `0` limits do not restrict traffic.

## Gateway Flow

The gateway already resolves the provider model before quota enforcement. Keep the existing generic `QuotaService` checks, then run model quota checks with the resolved model id. On success, record actual usage against both the generic quotas and the model quota counters.

## Admin UI

Expose a new "API Key Model Quotas" resource in the Access section. The table supports create, edit, disable, and filters for API key, model, and status. Field labels should make it clear that empty or `0` values mean unlimited.

## Tests

Add service tests for unlimited semantics, exceeded-limit semantics, and model-specific counter keys. Add static checks that the migration and admin resource are declared.
