# TokenHub

[简体中文](README.md)

TokenHub is a self-hosted gateway for model APIs. Administrators connect providers, manage model access and quotas, and track requests, token usage, and costs. The `1.0.4` source build adds an **English** language option to the admin console (`/admin/`), management app (`/app/`), and operations dashboard (`/screen/`). Select it before signing in or from the toolbar. Each entry point remembers your selection on that browser and site.

## Install with Docker

For a source checkout, start Docker and run the following from the repository root. The example exposes TokenHub on your machine only.

```sh
bash deploy/docker/install.sh --build --site http://localhost \
  --bind 127.0.0.1 --http-port 8080 --https-port 8443 --yes
bash deploy/docker/verify.sh
```

Open `/admin/` on port `8080` and select **English**. Sign in with the initial administrator credentials printed by the installer, change the password, and remove `TOKENHUB_ADMIN_PASSWORD` from the installation's `.env`. Keep the application key and backups in secure storage. If the initial password has already been used, sign in with the current password or follow the recovery procedure. [Initial administrator credentials and recovery](docs/deployment/initial-admin-password.md)

For an existing deployment using published images, choose a tag and architecture listed in the version guide. Source builds use the version in `VERSION` by default. On Windows, use the matching PowerShell scripts with Docker Desktop and WSL 2. A network deployment should configure a domain and HTTPS before inviting users. [Published versions](docs/deployment/versions.md) · [Deployment options](docs/deployment/README.md)

## Send your first model request

1. Under **Model Services**, add a provider and save its upstream API key. Import or configure a model and note the model name exposed by TokenHub.
2. Under **Organization & Access**, choose a department and user. In **API Access**, create an API key with access to that model. Store the key securely when it is displayed.
3. Set `TOKENHUB_API_KEY` and `TOKENHUB_MODEL` in your shell, then send a chat completion request.

```sh
curl --fail-with-body http://localhost:8080/v1/chat/completions \
  -H "Authorization: Bearer $TOKENHUB_API_KEY" \
  -H 'Content-Type: application/json' \
  --data "{\"model\":\"$TOKENHUB_MODEL\",\"messages\":[{\"role\":\"user\",\"content\":\"Say hello in one sentence.\"}]}"
```

Open **Usage Records** to check the model, token counts, and status. If a request fails, use its request ID to look up the error. Check the API key and allowed model for a `401` or `403`, the quota and rate limits for a `429`, and provider health and credentials for an upstream error. [API reference](docs/external-api-complete.md)

## Manage knowledge bases and devices

- Under **RAG Knowledge Base**, create a knowledge base and a dataset, upload a document, then wait for parsing and indexing. Use the retrieval playground to confirm that a query finds the expected passage. Configure access for the intended department, user, or API key. [RAG operations](docs/rag-vector-operations.md)
- Under **Operations**, create a dashboard share scoped to the intended viewers and metrics. Open the share link and select **English** on the dashboard. Test expiry and the permitted metrics before sharing the link. [Operations dashboard](docs/tokenhub-usage-management-guide.md#10-大屏和大屏分享)
- Open **App devices** in the admin console to generate a sign-in link. Open or paste the link into the management app and approve the device if approval is enabled. Choose **English** in the app toolbar. Push notifications require the deployment's Web Push or native push configuration. [Management app](docs/app-admin.md)

## Upgrade and troubleshoot

Use the upgrade script in your existing deployment directory. Take a database and storage backup, check the target version, and verify the services and data after the upgrade. For a source-built Docker deployment, run these commands after updating the source used by that deployment.

```sh
bash deploy/docker/backup.sh
bash deploy/docker/upgrade.sh --build
bash deploy/docker/verify.sh
```

For a registry deployment, use the published upgrade script and the tag for your architecture. If a migration ran and you need to roll back, follow the backup restoration procedure before restarting the older version. [Upgrade and rollback](docs/deployment/upgrade.md) · [Backup and restore](docs/deployment/backup-restore.md)

Run the verification script if the site does not respond. Check Caddy and PHP first for page or API failures, then MySQL, Redis, and the queue workers for slow or stalled background jobs. The **Service Status** page and request IDs help correlate user-visible errors with logs. [Onboarding and troubleshooting](docs/deployment/onboarding-and-troubleshooting.md)
