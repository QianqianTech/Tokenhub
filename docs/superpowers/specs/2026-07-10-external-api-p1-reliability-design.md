# External API P1 Reliability Design

P1 makes external API lifecycle operations dependable without changing API Key authentication. Events use a stable source-and-id cursor; files expose cursor pagination and delete physical local files after authorization; usage endpoints disclose queued finalization. Webhook secrets are encrypted at rest and support a 24-hour dual-signature rotation overlap. Documentation states that sync records are shared by all keys owned by the same user.

The change is test-first and uses existing MySQL, Redis, queue, and Crypto facilities. Existing one-time webhook creation secrets remain supported; rotation returns the replacement once and keeps the prior encrypted secret until its overlap expiry.
