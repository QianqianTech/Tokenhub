#!/usr/bin/env sh
set -eu

REDIS_HOST="${REDIS_HOST:-redis}"
REDIS_PORT="${REDIS_PORT:-6379}"
REDIS_PASSWORD="${REDIS_PASSWORD:-}"
QUEUE_NAME="${QUEUE_NAME:-tokenhub:jobs}"
PROCESSING_QUEUE_NAME="${PROCESSING_QUEUE_NAME:-${QUEUE_NAME}:processing}"
WORKER_AUTOSCALE_MIN="${WORKER_AUTOSCALE_MIN:-2}"
WORKER_AUTOSCALE_MAX="${WORKER_AUTOSCALE_MAX:-8}"
WORKER_AUTOSCALE_TASKS_PER_WORKER="${WORKER_AUTOSCALE_TASKS_PER_WORKER:-50}"
WORKER_AUTOSCALE_INTERVAL_SECONDS="${WORKER_AUTOSCALE_INTERVAL_SECONDS:-15}"
WORKER_AUTOSCALE_COOLDOWN_SECONDS="${WORKER_AUTOSCALE_COOLDOWN_SECONDS:-300}"
TOKENHUB_COMPOSE_PROJECT_DIR="${TOKENHUB_COMPOSE_PROJECT_DIR:-/srv/app}"
TOKENHUB_COMPOSE_FILE="${TOKENHUB_COMPOSE_FILE:-${TOKENHUB_COMPOSE_PROJECT_DIR}/docker-compose.yml}"
TOKENHUB_COMPOSE_PROJECT_NAME="${TOKENHUB_COMPOSE_PROJECT_NAME:-tokenhub}"
WORKER_AUTOSCALE_STATE_FILE="${WORKER_AUTOSCALE_STATE_FILE:-/tmp/tokenhub-worker-autoscaler.state}"

redis_llen() {
  queue="$1"
  if [ -n "$REDIS_PASSWORD" ]; then
    redis-cli -h "$REDIS_HOST" -p "$REDIS_PORT" -a "$REDIS_PASSWORD" --raw LLEN "$queue"
    return
  fi
  redis-cli -h "$REDIS_HOST" -p "$REDIS_PORT" --raw LLEN "$queue"
}

compose() {
  docker compose --project-name "$TOKENHUB_COMPOSE_PROJECT_NAME" --project-directory "$TOKENHUB_COMPOSE_PROJECT_DIR" -f "$TOKENHUB_COMPOSE_FILE" "$@"
}

current_replicas() {
  compose ps -q queue-worker 2>/dev/null | sed '/^$/d' | wc -l | tr -d ' '
}

desired_replicas() {
  total="$1"
  if [ "$total" -le 0 ]; then
    echo "$WORKER_AUTOSCALE_MIN"
    return
  fi

  desired=$(( (total + WORKER_AUTOSCALE_TASKS_PER_WORKER - 1) / WORKER_AUTOSCALE_TASKS_PER_WORKER ))
  if [ "$desired" -lt "$WORKER_AUTOSCALE_MIN" ]; then
    desired="$WORKER_AUTOSCALE_MIN"
  fi
  if [ "$desired" -gt "$WORKER_AUTOSCALE_MAX" ]; then
    desired="$WORKER_AUTOSCALE_MAX"
  fi
  echo "$desired"
}

last_scale_at() {
  if [ ! -f "$WORKER_AUTOSCALE_STATE_FILE" ]; then
    echo 0
    return
  fi
  cut -d ' ' -f 1 "$WORKER_AUTOSCALE_STATE_FILE"
}

record_scale() {
  replicas="$1"
  printf '%s %s\n' "$(date +%s)" "$replicas" >"$WORKER_AUTOSCALE_STATE_FILE"
}

scale_workers() {
  replicas="$1"
  compose up -d --no-build --scale "queue-worker=${replicas}" queue-worker
  record_scale "$replicas"
}

while true; do
  pending="$(redis_llen "$QUEUE_NAME")"
  processing="$(redis_llen "$PROCESSING_QUEUE_NAME")"
  total=$((pending + processing))
  desired="$(desired_replicas "$total")"
  current="$(current_replicas)"
  now="$(date +%s)"
  last="$(last_scale_at)"

  if [ "$current" -eq 0 ]; then
    scale_workers "$desired"
  elif [ "$desired" -gt "$current" ]; then
    scale_workers "$desired"
  elif [ "$desired" -lt "$current" ] && [ $((now - last)) -ge "$WORKER_AUTOSCALE_COOLDOWN_SECONDS" ]; then
    scale_workers "$desired"
  else
    echo "worker autoscale noop pending=${pending} processing=${processing} current=${current} desired=${desired}"
  fi

  sleep "$WORKER_AUTOSCALE_INTERVAL_SECONDS"
done
