#!/usr/bin/env bash
# TokenHub Docker installer for Linux and macOS (Windows: install.ps1).
#
#   ./install.sh                                   interactive; asks for the domain or IP
#   ./install.sh --site tokenhub.example.com       public domain, automatic HTTPS (ports 80/443)
#   ./install.sh --site https://10.0.0.8           IP access over HTTPS (Caddy internal CA)
#   ./install.sh --site 10.0.0.8 --http-port 8080  plain HTTP by IP
#   ./install.sh --build                           build images from this source tree
#   ./install.sh --image-prefix registry.example.com/tokenhub/ --tag 1.0.0   pull from a registry
#
# Re-running keeps the existing .env and simply (re)starts the stack.
set -euo pipefail

# shellcheck source=lib.sh
. "$(cd "$(dirname "$0")" && pwd)/lib.sh"

SITE="" SITE_GIVEN=0 HTTP_PORT="" HTTPS_PORT="" BIND="" BUILD=0 PREFIX="" TAG="" VECTOR="" ADMIN_USER="" ADMIN_PASSWORD="" ASSUME_YES=0 START=1

usage() {
  sed -n '2,13p' "$0" | sed 's/^# \{0,1\}//'
  cat <<'TXT'
Options:
  --site ADDRESS          domain, IP, https://IP, or several comma separated (default: plain HTTP)
  --http-port N           host HTTP port (default 80)
  --https-port N          host HTTPS port (default 443)
  --bind ADDRESS          host address to publish on (default 0.0.0.0)
  --build                 build the images from source (needs the full source tree)
  --image-prefix PREFIX   registry path, e.g. registry.example.com/tokenhub/
  --tag TAG               image tag (default: VERSION file or "local")
  --vector-store NAME     qdrant (default, recommended) or mysql_fallback
  --admin-user NAME       first administrator (default admin)
  --admin-password PASS   first administrator password (default: generated)
  --no-start              only write .env
  -y, --yes               do not ask questions
TXT
}

while [ $# -gt 0 ]; do
  case "$1" in
    --site) SITE="${2:-}"; SITE_GIVEN=1; shift 2 ;;
    --http-port) HTTP_PORT="${2:-}"; shift 2 ;;
    --https-port) HTTPS_PORT="${2:-}"; shift 2 ;;
    --bind) BIND="${2:-}"; shift 2 ;;
    --build) BUILD=1; shift ;;
    --image-prefix) PREFIX="${2:-}"; shift 2 ;;
    --tag) TAG="${2:-}"; shift 2 ;;
    --vector-store) VECTOR="${2:-}"; shift 2 ;;
    --admin-user) ADMIN_USER="${2:-}"; shift 2 ;;
    --admin-password) ADMIN_PASSWORD="${2:-}"; shift 2 ;;
    --no-start) START=0; shift ;;
    -y|--yes) ASSUME_YES=1; shift ;;
    -h|--help) usage; exit 0 ;;
    *) usage >&2; die "Unknown option: $1" ;;
  esac
done

require_docker

SOURCE_ROOT="$(cd "$KIT_DIR/../.." 2>/dev/null && pwd || true)"
if [ "$BUILD" = 1 ] && [ ! -f "$SOURCE_ROOT/docker/release/php/Dockerfile" ]; then
  die "--build needs the full TokenHub source tree (deploy/docker inside the repository)."
fi
export TOKENHUB_BUILD="$BUILD"

# ------------------------------------------------------------------ .env
site_settings() {
  # Mirrors app/Console/Install/SiteAddress.php.
  local input="$1" first host scheme addresses="" tls="" global="" kind_ip_https=0 kind_domain=0 entry
  APP_URL_VALUE="" SESSION_SECURE_VALUE=false
  if [ -z "$input" ] || [ "$input" = ":80" ]; then
    SITE_ADDRESS=":80" CADDY_TLS="" CADDY_GLOBAL=""
    host="$(detect_host_ip)"
    APP_URL_VALUE="http://$host$([ "$HTTP_PORT" = 80 ] || printf ':%s' "$HTTP_PORT")"
    return
  fi
  first=""
  IFS=',' read -r -a entries <<<"$input"
  for entry in "${entries[@]}"; do
    entry="$(printf '%s' "$entry" | tr -d '[:space:]')"
    [ -n "$entry" ] || continue
    scheme=""
    case "$entry" in
      https://*) scheme=https; entry="${entry#https://}" ;;
      http://*) scheme=http; entry="${entry#http://}" ;;
    esac
    entry="${entry%/}"
    host="${entry%%:*}"
    if printf '%s' "$host" | grep -Eq '^[0-9]+(\.[0-9]+){3}$'; then
      scheme="${scheme:-http}"
      if [ "$scheme" = https ]; then
        addresses="$addresses${addresses:+, }https://$entry"; kind_ip_https=1
        [ -n "$global" ] || global="default_sni $host"
      else
        case ", $addresses," in *", :80,"*) ;; *) addresses="$addresses${addresses:+, }:80" ;; esac
      fi
    else
      printf '%s' "$host" | grep -Eiq '^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)*$' || die "Invalid domain or IP: $host"
      if [ "$host" = localhost ]; then scheme="${scheme:-http}"; else scheme="${scheme:-https}"; fi
      if [ "$scheme" = https ]; then addresses="$addresses${addresses:+, }$entry"; kind_domain=1; else addresses="$addresses${addresses:+, }http://$entry"; fi
    fi
    if [ -z "$first" ]; then
      first="$scheme://$host"
      if [ "$scheme" = https ]; then
        SESSION_SECURE_VALUE=true
        APP_URL_VALUE="$first$([ "$HTTPS_PORT" = 443 ] || printf ':%s' "$HTTPS_PORT")"
      else
        APP_URL_VALUE="$first$([ "$HTTP_PORT" = 80 ] || printf ':%s' "$HTTP_PORT")"
      fi
    fi
  done
  if [ "$kind_ip_https" = 1 ] && [ "$kind_domain" = 0 ]; then tls="tls internal"; fi
  SITE_ADDRESS="$addresses" CADDY_TLS="$tls" CADDY_GLOBAL="$global"
}

if [ -f "$ENV_FILE" ]; then
  info "Using existing $ENV_FILE (delete it to start over with new secrets)."
else
  TEMPLATE="$KIT_DIR/.env.example"
  [ -f "$TEMPLATE" ] || TEMPLATE="$SOURCE_ROOT/.env.example"
  [ -f "$TEMPLATE" ] || die ".env.example not found next to install.sh or in the source tree."

  if [ "$SITE_GIVEN" = 0 ] && [ "$ASSUME_YES" = 0 ] && [ -t 0 ]; then
    printf 'How will users reach TokenHub?\n'
    printf '  - a domain (tokenhub.example.com) gets an automatic HTTPS certificate\n'
    printf '  - https://<IP> uses HTTPS with a self-signed internal CA\n'
    printf '  - <IP> or empty uses plain HTTP\n'
    read -r -p 'Domain or IP (several separated by commas): ' SITE
  fi

  if [ -z "$HTTP_PORT" ]; then HTTP_PORT=80; fi
  if [ -z "$HTTPS_PORT" ]; then HTTPS_PORT=443; fi
  site_settings "$SITE"
  case "$SITE_ADDRESS" in
    :80) ;;
    *)
      if [ "$SESSION_SECURE_VALUE" = true ] && printf '%s' "$SITE" | grep -Eqv '^https://[0-9]' && { [ "$HTTP_PORT" != 80 ] || [ "$HTTPS_PORT" != 443 ]; }; then
        warn "Automatic certificates for a domain need host ports 80 and 443; you chose $HTTP_PORT/$HTTPS_PORT."
      fi
      ;;
  esac

  if [ -z "$TAG" ]; then
    if [ -f "$SOURCE_ROOT/VERSION" ]; then TAG="$(tr -d '[:space:]' <"$SOURCE_ROOT/VERSION")"; elif [ -f "$KIT_DIR/VERSION" ]; then TAG="$(tr -d '[:space:]' <"$KIT_DIR/VERSION")"; else TAG=local; fi
  fi
  ADMIN_USER="${ADMIN_USER:-admin}"
  ADMIN_PASSWORD="${ADMIN_PASSWORD:-$(random_alnum 20)}"
  [ "${#ADMIN_PASSWORD}" -ge 12 ] || die "The administrator password must have at least 12 characters."
  case "$ADMIN_PASSWORD" in *[\$\#\'\"\\\ ]*) die "The administrator password must not contain \$ # quotes, backslashes or spaces (they break .env parsing)." ;; esac

  umask 077
  cp "$TEMPLATE" "$ENV_FILE"
  env_set APP_ENV production
  env_set APP_DEBUG false
  env_set APP_KEY "$(random_alnum 48)"
  env_set ADMIN_CSRF_SECRET "$(random_alnum 40)"
  env_set REALTIME_INTERNAL_TOKEN "$(random_alnum 48)"
  env_set APP_URL "$APP_URL_VALUE"
  env_set SESSION_SECURE "$SESSION_SECURE_VALUE"
  env_set TOKENHUB_SITE_ADDRESS "$SITE_ADDRESS"
  env_set TOKENHUB_CADDY_TLS "$CADDY_TLS"
  env_set TOKENHUB_CADDY_GLOBAL "$CADDY_GLOBAL"
  env_set TOKENHUB_BIND_ADDRESS "${BIND:-0.0.0.0}"
  env_set TOKENHUB_HTTP_PORT "$HTTP_PORT"
  env_set TOKENHUB_HTTPS_PORT "$HTTPS_PORT"
  env_set TOKENHUB_IMAGE_PREFIX "$PREFIX"
  env_set TOKENHUB_IMAGE_TAG "$TAG"
  env_set TOKENHUB_ADMIN_USERNAME "$ADMIN_USER"
  env_set TOKENHUB_ADMIN_PASSWORD "$ADMIN_PASSWORD"
  env_set DB_HOST mysql
  env_set DB_PASSWORD "$(random_alnum 32)"
  env_set DB_ROOT_PASSWORD "$(random_alnum 32)"
  env_set REDIS_HOST redis
  env_set REDIS_PASSWORD "$(random_alnum 32)"
  env_set QDRANT_URL http://qdrant:6333
  env_set QDRANT_API_KEY "$(random_alnum 32)"
  env_set RAG_VECTOR_STORE "${VECTOR:-qdrant}"
  env_set CADDY_VALIDATE_COMMAND ""
  env_set CADDY_RELOAD_COMMAND ""
  chmod 600 "$ENV_FILE"
  info "Wrote $ENV_FILE with random secrets. Keep a copy of APP_KEY in a safe place."
fi

[ "$START" = 1 ] || exit 0

# ------------------------------------------------------------------ images
if [ "$BUILD" = 1 ]; then
  info "Building images from source (first build takes several minutes)..."
  compose build
elif [ -n "$(env_get TOKENHUB_IMAGE_PREFIX)" ]; then
  info "Pulling images..."
  compose pull
else
  for image in tokenhub-php tokenhub-caddy tokenhub-rag-worker; do
    docker image inspect "$image:$(env_get TOKENHUB_IMAGE_TAG)" >/dev/null 2>&1 \
      || die "Image $image:$(env_get TOKENHUB_IMAGE_TAG) not found. Use --build (source tree), --image-prefix (registry) or load the offline bundle first."
  done
fi

# ------------------------------------------------------------------ start
info "Starting TokenHub..."
compose up -d --remove-orphans || { compose logs --no-color --tail=80 init >&2 || true; die "Starting the stack failed (init log above)."; }
wait_for_init 900
info "Waiting for the web services..."
wait_for_healthy php 300
wait_for_healthy caddy 120

info "Self-check:"
compose exec -T php php bin/doctor.php || warn "Some checks reported problems (see above)."

APP_URL_NOW="$(env_get APP_URL)"
cat <<TXT

TokenHub is running.
  Admin console : ${APP_URL_NOW%/}/admin/
  API base URL  : ${APP_URL_NOW%/}/v1
  Administrator : $(env_get TOKENHUB_ADMIN_USERNAME)
  Password      : $(env_get TOKENHUB_ADMIN_PASSWORD)

Next steps:
  1. Log in, change the administrator password, then remove TOKENHUB_ADMIN_PASSWORD from $ENV_FILE.
  2. Back up $ENV_FILE (APP_KEY decrypts stored provider secrets).
  3. Schedule backups: $KIT_DIR/backup.sh
TXT
