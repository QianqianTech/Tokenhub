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

SITE="" SITE_GIVEN=0 PUBLIC_IP=0 HTTP_PORT="" HTTPS_PORT="" BIND="" BUILD=0 PREFIX="" TAG="" VECTOR="" ADMIN_USER="" ADMIN_PASSWORD="" ASSUME_YES=0 START=1 CHECK_ONLY=0

usage() {
  sed -n '2,13p' "$0" | sed 's/^# \{0,1\}//'
  cat <<'TXT'
Options:
  --site ADDRESS          domain, IP, https://IP, or several comma separated (default: plain HTTP)
  --public-ip             detect the public IPv4 and use HTTPS (port 80 enables public certificates)
  --http-port N           host HTTP port (default 80)
  --https-port N          host HTTPS port (default 443)
  --bind ADDRESS          host address to publish on (default 0.0.0.0)
  --build                 build the images from source (needs the full source tree)
  --image-prefix PREFIX   registry path, e.g. registry.example.com/tokenhub/
  --tag TAG               image tag (default: VERSION file or "local")
  --vector-store NAME     qdrant (default, recommended) or mysql_fallback
  --admin-user NAME       first administrator (default admin)
  --admin-password PASS   first administrator password (default: generated)
  --check                 check Docker and source prerequisites only
  --no-start              only write .env
  -y, --yes               do not ask questions
TXT
}

while [ $# -gt 0 ]; do
  case "$1" in
    --site) SITE="${2:-}"; SITE_GIVEN=1; shift 2 ;;
    --public-ip) PUBLIC_IP=1; shift ;;
    --http-port) HTTP_PORT="${2:-}"; shift 2 ;;
    --https-port) HTTPS_PORT="${2:-}"; shift 2 ;;
    --bind) BIND="${2:-}"; shift 2 ;;
    --build) BUILD=1; shift ;;
    --image-prefix) PREFIX="${2:-}"; shift 2 ;;
    --tag) TAG="${2:-}"; shift 2 ;;
    --vector-store) VECTOR="${2:-}"; shift 2 ;;
    --admin-user) ADMIN_USER="${2:-}"; shift 2 ;;
    --admin-password) ADMIN_PASSWORD="${2:-}"; shift 2 ;;
    --check) CHECK_ONLY=1; shift ;;
    --no-start) START=0; shift ;;
    -y|--yes) ASSUME_YES=1; shift ;;
    -h|--help) usage; exit 0 ;;
    *) usage >&2; die "Unknown option: $1" ;;
  esac
done

if [ "$PUBLIC_IP" = 1 ]; then
  [ "$SITE_GIVEN" = 0 ] || die "Choose --site or --public-ip."
  case "$BIND" in 127.*|::1|'[::1]'|localhost) die "--public-ip requires a non-loopback bind address." ;; esac
fi

require_docker
docker_os="$(docker info --format '{{.OSType}}')" || die "Cannot read the Docker server OS."
[ "$docker_os" = linux ] || die "TokenHub needs Docker in Linux container mode."
engine="$(docker version --format '{{.Server.Version}}')" || die "Cannot read the Docker Engine version."
[[ "$engine" =~ ^([0-9]+)\. ]] && [ "${BASH_REMATCH[1]}" -ge 24 ] || die "Docker Engine 24+ is required (found $engine)."

SOURCE_ROOT="$(cd "$KIT_DIR/../.." 2>/dev/null && pwd || true)"
if [ "$BUILD" = 1 ]; then
  [ -f "$SOURCE_ROOT/docker/release/php/Dockerfile" ] || die "--build needs the full TokenHub source tree (deploy/docker inside the repository)."
  if [ "$START" = 1 ]; then
    docker buildx version >/dev/null 2>&1 || die "Docker Buildx is required for source builds (Ubuntu: install the docker-buildx-plugin package)."
  fi
fi
if [ "$CHECK_ONLY" = 1 ]; then
  info "Docker prerequisites passed (Engine $engine)."
  exit 0
fi
export TOKENHUB_BUILD="$BUILD"

# ------------------------------------------------------------------ .env
is_public_ipv4() {
  local octet='(0|[1-9][0-9]{0,2})' a b
  [[ "$1" =~ ^$octet\.$octet\.$octet\.$octet$ ]] || return 1
  a="${BASH_REMATCH[1]}" b="${BASH_REMATCH[2]}"
  (( a > 0 && a < 224 && a != 10 && a != 127 && b <= 255
     && BASH_REMATCH[3] <= 255 && BASH_REMATCH[4] <= 255
     && !(a == 100 && b >= 64 && b <= 127) && !(a == 169 && b == 254)
     && !(a == 172 && b >= 16 && b <= 31) && !(a == 192 && b == 168) ))
}

detect_public_ipv4() {
  local address
  if command -v ip >/dev/null 2>&1; then
    # A route lookup can identify a directly assigned public IP without making a network request.
    address="$(ip -4 route get 1.1.1.1 2>/dev/null | awk 'NR == 1 {for (i = 1; i < NF; i++) if ($i == "src") print $(i+1)}')" || address=""
    if is_public_ipv4 "$address"; then printf '%s\n' "$address"; return; fi
  fi
  command -v curl >/dev/null 2>&1 || return 1
  local curl_args=(-q -4 -fsS --noproxy '*' --connect-timeout 4 --max-time 8)
  address="$(curl "${curl_args[@]}" https://api.ipify.org 2>/dev/null)" || address=""
  if ! is_public_ipv4 "$address"; then
    address="$(curl "${curl_args[@]}" https://www.cloudflare.com/cdn-cgi/trace 2>/dev/null | sed -n 's/^ip=//p')" || return 1
    is_public_ipv4 "$address" || return 1
  fi
  printf '%s\n' "$address"
}

site_settings() {
  # Mirrors app/Console/Install/SiteAddress.php.
  local input="$1" first host scheme port addresses="" tls="" global="" kind_ip_https=0 kind_domain=0 entry
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
        addresses="$addresses${addresses:+, }https://$host"; kind_ip_https=1
        [ -n "$global" ] || global="default_sni $host"
      else
        case ", $addresses," in *", :80,"*) ;; *) addresses="$addresses${addresses:+, }:80" ;; esac
      fi
    else
      printf '%s' "$host" | grep -Eiq '^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)*$' || die "Invalid domain or IP: $host"
      if [ "$host" = localhost ]; then scheme="${scheme:-http}"; else scheme="${scheme:-https}"; fi
      if [ "$scheme" = https ]; then addresses="$addresses${addresses:+, }$host"; kind_domain=1; else addresses="$addresses${addresses:+, }http://$host"; fi
    fi
    port="$HTTP_PORT"
    [ "$scheme" = http ] || port="$HTTPS_PORT"
    case "$entry" in
      "$host"|"$host:$port") ;;
      *) die "Use a domain or IP in --site and set the published port with --${scheme}-port." ;;
    esac
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
  info "Using existing configuration and secrets from $ENV_FILE."
  if [ "$PUBLIC_IP" = 1 ]; then
    info "Reusing the saved site. To change the address, see the access settings in deploy/docker/README.md."
  fi
else
  TEMPLATE="$KIT_DIR/.env.example"
  [ -f "$TEMPLATE" ] || TEMPLATE="$SOURCE_ROOT/.env.example"
  [ -f "$TEMPLATE" ] || die ".env.example not found next to install.sh or in the source tree."

  if [ "$PUBLIC_IP" = 1 ]; then
    public_ip="$(detect_public_ipv4)" \
      || die "Could not detect the public IPv4. Set --site https://<public-ip> or specify a domain."
    SITE="https://$public_ip"
    info "Detected public IPv4: $public_ip"
  elif [ "$SITE_GIVEN" = 0 ] && [ "$ASSUME_YES" = 0 ] && [ -t 0 ]; then
    printf 'How will users reach TokenHub?\n'
    printf '  - a domain (tokenhub.example.com) gets an automatic HTTPS certificate\n'
    printf '  - https://<IP> uses HTTPS with a self-signed internal CA\n'
    printf '  - <IP> or empty uses plain HTTP\n'
    read -r -p 'Domain or IP (several separated by commas): ' SITE
  fi

  if [ -z "$HTTP_PORT" ]; then HTTP_PORT=80; fi
  if [ -z "$HTTPS_PORT" ]; then HTTPS_PORT=443; fi
  site_settings "$SITE"
  if [ "$PUBLIC_IP" = 1 ] && [ "$HTTP_PORT" = 80 ]; then
    # Keep the TLS policy on one dotenv line. Compose expands the escaped newlines for Caddy.
    CADDY_TLS='"tls {\n  issuer acme https://acme-v02.api.letsencrypt.org/directory {\n    profile shortlived\n    disable_tlsalpn_challenge\n  }\n  issuer internal\n}"'
  fi
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
  if [ "$SESSION_SECURE_VALUE" = true ] && [ "$HTTPS_PORT" != 443 ] && [[ "$SITE_ADDRESS" != *,* ]]; then
    env_set TOKENHUB_HTTP_REDIRECT_URL "$APP_URL_VALUE"
  fi
  env_set TOKENHUB_IMAGE_PREFIX "$PREFIX"
  env_set TOKENHUB_IMAGE_TAG "$TAG"
  if [ -n "${COMPOSE_PROJECT_NAME:-}" ]; then env_set COMPOSE_PROJECT_NAME "$COMPOSE_PROJECT_NAME"; fi
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
pull_images() {
  info "Preparing Docker images..."
  if ! compose pull "$@"; then
    warn "Image pull failed. Retrying once..."
    sleep 2
    compose pull "$@" || die "Image pull failed. Check the Docker daemon's registry access and the image tag and architecture. Configuration is preserved in $ENV_FILE."
  fi
}

up_options=(--no-build)
if [ "$BUILD" = 1 ]; then
  pull_images mysql redis qdrant
  info "Building images from source (first build downloads base images and packages)..."
  compose build || die "Source build failed. Check BuildKit registry and build dependency access (see deploy/docker/README.md). Fix the cause and rerun with --build. Configuration is preserved in $ENV_FILE."
  up_options+=(--pull never)
elif [ -n "$(env_get TOKENHUB_IMAGE_PREFIX)" ]; then
  pull_images
  up_options+=(--pull never)
else
  for image in tokenhub-php tokenhub-caddy tokenhub-rag-worker tokenhub-realtime-gateway; do
    docker image inspect "$image:$(env_get TOKENHUB_IMAGE_TAG)" >/dev/null 2>&1 \
      || die "Image $image:$(env_get TOKENHUB_IMAGE_TAG) not found. Use --build (source tree), --image-prefix (registry) or load the offline bundle first."
  done
fi

# ------------------------------------------------------------------ trusted proxies
# SEC-114: trust the stack's own Docker network as proxies, so X-Forwarded-For
# from Caddy and the realtime gateway is believed (Caddy replaces the header of
# untrusted clients, so it cannot be forged from outside). Only while the value
# is still the template default; doctor warns when all clients share one address.
detect_compose_subnets() {
  local project network
  project="$(env_get COMPOSE_PROJECT_NAME)"; project="${project:-tokenhub}"
  network="${project}_default"
  docker network inspect -f '{{range .IPAM.Config}}{{.Subnet}} {{end}}' "$network" 2>/dev/null | tr ' ' '\n' | grep -E '^[0-9a-fA-F:.]+/[0-9]+$' | paste -sd, - || true
}
current_trusted="$(env_get TRUSTED_PROXY_CIDRS)"
if [ -z "$current_trusted" ] || [ "$current_trusted" = "127.0.0.1/32,::1/128" ]; then
  compose up --no-start "${up_options[@]}" >/dev/null 2>&1 || true
  subnets="$(detect_compose_subnets)"
  if [ -n "$subnets" ]; then
    env_set TRUSTED_PROXY_CIDRS "127.0.0.1/32,::1/128,$subnets"
    info "TRUSTED_PROXY_CIDRS: added the Docker network $subnets"
  else
    warn "Could not detect the Docker network; TRUSTED_PROXY_CIDRS stays $current_trusted (bin/doctor.php reports if clients share one address)."
  fi
fi

# ------------------------------------------------------------------ start
info "Starting TokenHub..."
compose up -d --remove-orphans "${up_options[@]}" || { compose logs --no-color --tail=80 init >&2 || true; die "Starting the stack failed (see the error and init log above)."; }
wait_for_init 900
info "Waiting for the web services..."
wait_for_healthy php 300
wait_for_healthy caddy 120

info "Self-check:"
compose exec -T php php bin/doctor.php || warn "Some checks reported problems (see above)."

APP_URL_NOW="$(env_get APP_URL)"
case "$(env_get TOKENHUB_BIND_ADDRESS)" in
  127.*|::1|'[::1]'|localhost) info "Loopback binding: open the printed URL from the Docker host." ;;
esac
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
