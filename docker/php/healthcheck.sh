#!/usr/bin/env sh
# Container health checks for TokenHub PHP images.
#   tokenhub-healthcheck fpm            PHP-FPM answers its ping endpoint
#   tokenhub-healthcheck worker [secs]  the queue worker loop ran within the last N seconds (default 120)
set -eu

case "${1:-fpm}" in
  fpm)
    SCRIPT_NAME=/fpm-ping SCRIPT_FILENAME=/fpm-ping REQUEST_METHOD=GET \
      cgi-fcgi -bind -connect 127.0.0.1:9000 2>/dev/null | grep -q pong
    ;;
  worker)
    heartbeat="${TOKENHUB_WORKER_HEARTBEAT_FILE:-/tmp/tokenhub-queue-worker.heartbeat}"
    max_age="${2:-120}"
    test -f "$heartbeat" || exit 1
    age=$(( $(date +%s) - $(stat -c %Y "$heartbeat") ))
    test "$age" -lt "$max_age"
    ;;
  *)
    echo "usage: tokenhub-healthcheck fpm|worker [max-age-seconds]" >&2
    exit 64
    ;;
esac
