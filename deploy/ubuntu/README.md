# TokenHub Ubuntu Deployment

This directory contains Ubuntu production service assets:

- `php-fpm-tokenhub.conf`
- `tokenhub-worker.service`
- `tokenhub-self-check.sh`

Use the repository-level production docs for the full runbook:

- `docs/deployment/production.md`
- `docs/deployment/backup-restore.md`
- `docs/acceptance/final-acceptance-checklist.md`

## Minimal Install Flow

```bash
sudo apt update
sudo apt install -y php8.2-fpm php8.2-cli php8.2-mysql php8.2-redis php8.2-curl php8.2-mbstring mysql-server redis-server caddy unzip
sudo mkdir -p /var/www/tokenhub /etc/tokenhub /var/log/tokenhub /var/lib/tokenhub/{exports,media,caddy}
sudo chown -R www-data:www-data /var/www/tokenhub /var/log/tokenhub /var/lib/tokenhub
sudo cp deploy/ubuntu/php-fpm-tokenhub.conf /etc/php/8.2/fpm/pool.d/tokenhub.conf
sudo cp deploy/ubuntu/tokenhub-worker.service /etc/systemd/system/tokenhub-worker.service
sudo cp deploy/ubuntu/.env.production.example /etc/tokenhub/tokenhub.env
sudo chown root:www-data /etc/tokenhub/tokenhub.env
sudo chmod 0640 /etc/tokenhub/tokenhub.env
sudo ln -sfn /etc/tokenhub/tokenhub.env /var/www/tokenhub/.env
sudo cp Caddyfile.example /etc/caddy/Caddyfile
sudo install -o www-data -g www-data -m 0644 /dev/null /var/lib/tokenhub/caddy/tls-current.caddy
sudo systemctl daemon-reload
sudo systemctl restart php8.2-fpm caddy
sudo systemctl enable --now tokenhub-worker
```

Before starting services, replace every `change-me` value, set the public `APP_URL`, and set `TRUSTED_PROXY_CIDRS` to only the actual Caddy/load-balancer peer addresses. Keep MySQL, Redis, and Qdrant private; only Caddy may listen on public ports.

`worker-autoscaler` is an explicit Docker Compose profile because it controls Docker through the host socket. Enable it only after setting host-resolvable `TOKENHUB_COMPOSE_PROJECT_DIR` and `TOKENHUB_COMPOSE_FILE`; otherwise run a fixed worker count under systemd or the platform orchestrator.

## Verify

```bash
curl -i https://your-domain.example/health
sudo bash /var/www/tokenhub/deploy/ubuntu/tokenhub-self-check.sh
BASE_URL=https://your-domain.example bash /var/www/tokenhub/scripts/security-smoke.sh
BASE_URL=https://your-domain.example ADMIN_PASSWORD='admin-password' bash /var/www/tokenhub/scripts/acceptance-check.sh
sudo journalctl -u tokenhub-worker -n 100 --no-pager
```
