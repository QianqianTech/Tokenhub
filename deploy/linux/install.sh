#!/usr/bin/env bash
# TokenHub source installation (no Docker) for Linux servers.
#
# Supported: Ubuntu 22.04 / 24.04, Debian 12, RHEL / CentOS Stream / Rocky / AlmaLinux 8 and 9
#            (openEuler, Kylin and other derivatives: best effort through the apt or dnf path).
# Windows:   use Docker Desktop and deploy/docker/install.ps1 (PHP-FPM is not available on Windows).
#
#   sudo deploy/linux/install.sh --site tokenhub.example.com
#   sudo deploy/linux/install.sh --site https://10.0.0.8
#   sudo deploy/linux/install.sh --site 10.0.0.8 --db-host 10.0.0.20 --db-password '...'   external MySQL
#   sudo deploy/linux/install.sh --check                                                 only check the OS
#
# What it does: installs PHP 8.2+ (FPM), Caddy, MySQL, Redis, Qdrant and Python for the RAG worker,
# copies the application to /var/www/tokenhub, writes /etc/tokenhub/tokenhub.env with random secrets,
# creates the database, runs `php bin/install.php run`, and enables the systemd services.
# Running it again upgrades the code in place and keeps the existing configuration.
set -euo pipefail

SOURCE_DIR="$(cd "$(dirname "$0")/../.." && pwd)"
APP_DIR=/var/www/tokenhub
APP_USER=tokenhub
CONFIG_DIR=/etc/tokenhub
ENV_FILE=$CONFIG_DIR/tokenhub.env
RAG_DIR=/opt/tokenhub-rag-worker
PHP_TARGET=8.3
QDRANT_VERSION="${QDRANT_VERSION:-1.15.0}"
SITE="" ADMIN_USER=admin ADMIN_PASSWORD="" VECTOR=qdrant WORKERS=2 CHECK_ONLY=0 ASSUME_YES=0
DB_HOST="" DB_PORT=3306 DB_NAME=tokenhub DB_USER=tokenhub DB_PASSWORD=""
REDIS_HOST="" REDIS_PORT=6379 REDIS_PASSWORD="" QDRANT_URL="" QDRANT_ARCHIVE=""
INSTALL_MYSQL=1 INSTALL_REDIS=1 INSTALL_QDRANT=1 INSTALL_CADDY=1 OPEN_FIREWALL=1

info() { printf '\033[1;34m==>\033[0m %s\n' "$*"; }
warn() { printf '\033[1;33mWARN:\033[0m %s\n' "$*" >&2; }
die() { printf '\033[1;31mERROR:\033[0m %s\n' "$*" >&2; exit 1; }

usage() {
  sed -n '2,18p' "$0" | sed 's/^# \{0,1\}//'
  cat <<'TXT'
Options:
  --site ADDRESS          domain, IP, https://IP or several comma separated (default: plain HTTP)
  --admin-user NAME       first super administrator (default admin)
  --admin-password PASS   its password (default: generated and printed)
  --app-dir DIR           installation directory (default /var/www/tokenhub)
  --php-version X.Y       PHP version when a repository must be added (default 8.3; 8.2-8.5 supported)
  --workers N             queue worker processes (default 2)
  --vector-store NAME     qdrant (default) or mysql_fallback (no Qdrant)
  --db-host H --db-port P --db-name N --db-user U --db-password P   use an existing MySQL (8.0+, 8.4 LTS recommended)
  --redis-host H --redis-port P --redis-password P                    use an existing Redis
  --qdrant-url URL        use an existing Qdrant
  --qdrant-archive FILE   Qdrant release archive for servers without internet access
  --no-firewall           do not open ports 80/443 in firewalld/ufw
  --check                 only detect the OS and print the plan
  -y, --yes               do not ask questions
TXT
}

while [ $# -gt 0 ]; do
  case "$1" in
    --site) SITE="$2"; shift 2 ;;
    --admin-user) ADMIN_USER="$2"; shift 2 ;;
    --admin-password) ADMIN_PASSWORD="$2"; shift 2 ;;
    --app-dir) APP_DIR="$2"; shift 2 ;;
    --php-version) PHP_TARGET="$2"; shift 2 ;;
    --workers) WORKERS="$2"; shift 2 ;;
    --vector-store) VECTOR="$2"; shift 2 ;;
    --db-host) DB_HOST="$2"; INSTALL_MYSQL=0; shift 2 ;;
    --db-port) DB_PORT="$2"; shift 2 ;;
    --db-name) DB_NAME="$2"; shift 2 ;;
    --db-user) DB_USER="$2"; shift 2 ;;
    --db-password) DB_PASSWORD="$2"; shift 2 ;;
    --redis-host) REDIS_HOST="$2"; INSTALL_REDIS=0; shift 2 ;;
    --redis-port) REDIS_PORT="$2"; shift 2 ;;
    --redis-password) REDIS_PASSWORD="$2"; shift 2 ;;
    --qdrant-url) QDRANT_URL="$2"; INSTALL_QDRANT=0; shift 2 ;;
    --qdrant-archive) QDRANT_ARCHIVE="$2"; shift 2 ;;
    --no-firewall) OPEN_FIREWALL=0; shift ;;
    --check) CHECK_ONLY=1; shift ;;
    -y|--yes) ASSUME_YES=1; shift ;;
    -h|--help) usage; exit 0 ;;
    *) usage >&2; die "Unknown option: $1" ;;
  esac
done

# Re-runs (upgrade.sh) keep the choices of the first installation unless overridden on the command line.
if [ -f "$ENV_FILE" ]; then
  prior() { sed -n "s/^$1=//p" "$ENV_FILE" | tail -n1 | sed 's/^"//; s/"$//'; }
  case "$(prior DB_HOST)" in ''|127.0.0.1|localhost) ;; *) [ -n "$DB_HOST" ] || { DB_HOST="$(prior DB_HOST)"; INSTALL_MYSQL=0; } ;; esac
  case "$(prior REDIS_HOST)" in ''|127.0.0.1|localhost) ;; *) [ -n "$REDIS_HOST" ] || { REDIS_HOST="$(prior REDIS_HOST)"; INSTALL_REDIS=0; } ;; esac
  if [ "$(prior RAG_VECTOR_STORE)" = mysql_fallback ] && [ "$VECTOR" = qdrant ]; then VECTOR=mysql_fallback; fi
  case "$(prior QDRANT_URL)" in ''|http://127.0.0.1:6333|http://localhost:6333) ;; *) [ -n "$QDRANT_URL" ] || { QDRANT_URL="$(prior QDRANT_URL)"; INSTALL_QDRANT=0; } ;; esac
fi
[ "$VECTOR" = mysql_fallback ] && INSTALL_QDRANT=0
[ -f "$SOURCE_DIR/bin/install.php" ] || die "Run this script from the TokenHub source tree (deploy/linux/install.sh)."

# ------------------------------------------------------------------ OS detection
[ -r /etc/os-release ] || die "Cannot detect the operating system (/etc/os-release missing)."
# shellcheck disable=SC1091
. /etc/os-release
OS_ID="${ID:-unknown}" OS_LIKE="${ID_LIKE:-}" OS_VERSION="${VERSION_ID:-0}"
if command -v apt-get >/dev/null 2>&1; then
  FAMILY=debian
elif command -v dnf >/dev/null 2>&1; then
  FAMILY=rhel
elif command -v yum >/dev/null 2>&1; then
  die "$PRETTY_NAME uses yum only (CentOS 7 or older, end of life). Use RHEL/Rocky/AlmaLinux 8+ or the Docker installation."
else
  die "Unsupported operating system: ${PRETTY_NAME:-$OS_ID}. Use the Docker installation (deploy/docker)."
fi
RHEL_MAJOR="${OS_VERSION%%.*}"
ARCH="$(uname -m)"
case "$ARCH" in x86_64|aarch64) ;; *) die "Unsupported CPU architecture: $ARCH" ;; esac

info "Detected ${PRETTY_NAME:-$OS_ID} ($FAMILY, $ARCH)"
case "$OS_ID" in
  ubuntu|debian|rhel|centos|rocky|almalinux|ol) ;;
  *) warn "$OS_ID is not in the tested list; continuing with the $FAMILY package path." ;;
esac
cat <<TXT
Plan:
  application    $APP_DIR (user $APP_USER), configuration $ENV_FILE
  PHP            ${PHP_TARGET}+ with FPM          MySQL   $([ "$INSTALL_MYSQL" = 1 ] && echo "install locally" || echo "external $DB_HOST")
  Redis          $([ "$INSTALL_REDIS" = 1 ] && echo "install locally" || echo "external $REDIS_HOST")       Qdrant  $([ "$INSTALL_QDRANT" = 1 ] && echo "install $QDRANT_VERSION" || echo "${QDRANT_URL:-not used ($VECTOR)}")
  Web entry      Caddy, site '${SITE:-:80 (plain HTTP)}'
TXT
[ "$CHECK_ONLY" = 1 ] && exit 0
[ "$(id -u)" = 0 ] || die "Run as root (sudo)."

random_alnum() { LC_ALL=C tr -dc 'A-HJ-NP-Za-km-z2-9' </dev/urandom 2>/dev/null | head -c "${1:-32}" || true; }

# ------------------------------------------------------------------ packages
php_version_ok() { command -v php >/dev/null 2>&1 && php -r 'exit(PHP_VERSION_ID >= 80200 && PHP_VERSION_ID < 80600 ? 0 : 1);'; }

install_debian() {
  export DEBIAN_FRONTEND=noninteractive
  apt-get update -q
  apt-get install -y -q ca-certificates curl gnupg lsb-release unzip rsync python3 python3-venv python3-pip sudo cron tar
  local candidate
  candidate="$(apt-cache policy php-fpm 2>/dev/null | awk '/Candidate:/ {print $2}' | sed -E 's/^[0-9]+://; s/^([0-9]+\.[0-9]+).*/\1/')"
  if [ -n "$candidate" ] && [ "$(printf '%s\n8.2\n' "$candidate" | sort -V | head -n1)" = 8.2 ] && [ "$(printf '%s\n8.6\n' "$candidate" | sort -V | head -n1)" = "$candidate" ]; then
    PHP_VER="$candidate"
  else
    PHP_VER="$PHP_TARGET"
    info "Distribution PHP ($candidate) is outside 8.2-8.5; adding the PHP $PHP_VER repository"
    if [ "$OS_ID" = ubuntu ]; then
      apt-get install -y -q software-properties-common
      add-apt-repository -y ppa:ondrej/php
    else
      curl -fsSL https://packages.sury.org/php/apt.gpg -o /etc/apt/trusted.gpg.d/sury-php.gpg
      echo "deb https://packages.sury.org/php/ $(lsb_release -sc) main" >/etc/apt/sources.list.d/sury-php.list
    fi
    apt-get update -q
  fi
  apt-get install -y -q "php$PHP_VER-fpm" "php$PHP_VER-cli" "php$PHP_VER-mysql" "php$PHP_VER-redis" "php$PHP_VER-gd" \
    "php$PHP_VER-zip" "php$PHP_VER-xml" "php$PHP_VER-mbstring" "php$PHP_VER-curl" "php$PHP_VER-intl" "php$PHP_VER-opcache"
  PHP_BIN="/usr/bin/php$PHP_VER"
  FPM_SERVICE="php$PHP_VER-fpm"
  FPM_POOL_DIR="/etc/php/$PHP_VER/fpm/pool.d"
  FPM_SOCKET="/run/php/tokenhub-fpm.sock"
  [ "$INSTALL_REDIS" = 1 ] && apt-get install -y -q redis-server
  if [ "$INSTALL_MYSQL" = 1 ]; then apt-get install -y -q mysql-server || apt-get install -y -q default-mysql-server; fi
  apt-get install -y -q default-mysql-client || apt-get install -y -q mysql-client || true
  if [ "$INSTALL_CADDY" = 1 ] && ! command -v caddy >/dev/null 2>&1; then
    apt-get install -y -q debian-keyring debian-archive-keyring apt-transport-https || true
    curl -1sLf 'https://dl.cloudsmith.io/public/caddy/stable/gpg.key' | gpg --dearmor --yes -o /usr/share/keyrings/caddy-stable-archive-keyring.gpg
    curl -1sLf 'https://dl.cloudsmith.io/public/caddy/stable/debian.deb.txt' >/etc/apt/sources.list.d/caddy-stable.list
    apt-get update -q
    apt-get install -y -q caddy
  fi
  REDIS_SERVICE=redis-server
  REDIS_CONF=/etc/redis/redis.conf
  MYSQL_SERVICE=mysql
  # Debian ships MariaDB, whose unit only aliases mysql.service (systemctl refuses to enable an alias).
  if dpkg-query -W -f='${Status}' mariadb-server 2>/dev/null | grep -q 'install ok installed'; then
    MYSQL_SERVICE=mariadb
    warn "MariaDB was installed (the distribution has no MySQL). It is not tested; MySQL 8.4 LTS from https://dev.mysql.com/downloads/repo/apt/ is recommended."
  fi
  return 0
}

install_rhel() {
  dnf install -y -q dnf-plugins-core curl tar unzip rsync python3 python3-pip sudo policycoreutils-python-utils || true
  if ! php_version_ok; then
    info "Enabling EPEL and Remi for PHP $PHP_TARGET"
    if [ "$OS_ID" != fedora ]; then
      dnf install -y -q "https://dl.fedoraproject.org/pub/epel/epel-release-latest-$RHEL_MAJOR.noarch.rpm" || dnf install -y -q epel-release
      dnf install -y -q "https://rpms.remirepo.net/enterprise/remi-release-$RHEL_MAJOR.rpm"
    fi
    dnf module reset -y php >/dev/null 2>&1 || true
    dnf module enable -y "php:remi-$PHP_TARGET"
  fi
  dnf install -y -q php-fpm php-cli php-mysqlnd php-pecl-redis5 php-gd php-pecl-zip php-xml php-mbstring php-intl php-opcache php-process \
    || dnf install -y -q php-fpm php-cli php-mysqlnd php-pecl-redis php-gd php-pecl-zip php-xml php-mbstring php-intl php-opcache php-process
  PHP_BIN=/usr/bin/php
  FPM_SERVICE=php-fpm
  FPM_POOL_DIR=/etc/php-fpm.d
  FPM_SOCKET=/run/php-fpm/tokenhub-fpm.sock
  if [ "$INSTALL_REDIS" = 1 ]; then dnf install -y -q redis || dnf install -y -q valkey; fi
  if [ "$INSTALL_MYSQL" = 1 ]; then dnf install -y -q mysql-server; fi
  dnf install -y -q mysql || true
  if [ "$INSTALL_CADDY" = 1 ] && ! command -v caddy >/dev/null 2>&1; then
    dnf copr enable -y @caddy/caddy || true
    dnf install -y -q caddy
  fi
  REDIS_SERVICE=redis
  systemctl list-unit-files redis.service >/dev/null 2>&1 || REDIS_SERVICE=valkey
  REDIS_CONF=/etc/redis/redis.conf
  [ -f "$REDIS_CONF" ] || REDIS_CONF=/etc/redis.conf
  [ -f "$REDIS_CONF" ] || REDIS_CONF=/etc/valkey/valkey.conf
  MYSQL_SERVICE=mysqld
  if [ "$RHEL_MAJOR" = 8 ]; then dnf install -y -q python3.11 python3.11-pip || true; fi
  return 0
}

info "Installing packages"
if [ "$FAMILY" = debian ]; then install_debian; else install_rhel; fi
"$PHP_BIN" -r 'exit(PHP_VERSION_ID >= 80200 && PHP_VERSION_ID < 80600 ? 0 : 1);' || die "PHP $("$PHP_BIN" -r 'echo PHP_VERSION;') is not in the supported range 8.2-8.5."

# ------------------------------------------------------------------ user and directories
id "$APP_USER" >/dev/null 2>&1 || useradd --system --home-dir /var/lib/tokenhub --shell /usr/sbin/nologin "$APP_USER"
if id caddy >/dev/null 2>&1; then usermod -a -G "$APP_USER" caddy; fi
install -d -m 0750 -o root -g "$APP_USER" "$CONFIG_DIR"
install -d -m 0770 -o "$APP_USER" -g "$APP_USER" /var/log/tokenhub
install -d -m 0750 -o "$APP_USER" -g "$APP_USER" /var/lib/tokenhub /var/lib/tokenhub/exports /var/lib/tokenhub/media /var/lib/tokenhub/caddy
touch /var/lib/tokenhub/caddy/tls-current.caddy
chown "$APP_USER:$APP_USER" /var/lib/tokenhub/caddy/*.caddy

info "Copying the application to $APP_DIR"
install -d -m 0750 -o "$APP_USER" -g "$APP_USER" "$APP_DIR"
rsync -a --delete --filter='P vendor/' \
  --exclude '.git' --exclude '.env' --exclude 'storage/' --exclude 'deploy/docker/.env' --exclude 'deploy/docker/backups/' \
  --exclude 'node_modules/' --exclude '.composer-install.lock' \
  "$SOURCE_DIR/" "$APP_DIR/"
install -d -m 0750 -o "$APP_USER" -g "$APP_USER" "$APP_DIR/storage" "$APP_DIR/storage/logs" "$APP_DIR/storage/cache" "$APP_DIR/storage/exports" "$APP_DIR/storage/media"
ln -sfn /var/lib/tokenhub/caddy "$APP_DIR/storage/caddy"

if [ ! -f "$APP_DIR/vendor/autoload.php" ] || [ "$SOURCE_DIR/composer.lock" -nt "$APP_DIR/vendor/autoload.php" ]; then
  info "Installing PHP dependencies (composer)"
  if ! command -v composer >/dev/null 2>&1; then
    curl -fsSL https://getcomposer.org/installer -o /tmp/composer-setup.php
    "$PHP_BIN" /tmp/composer-setup.php --quiet --install-dir=/usr/local/bin --filename=composer
    rm -f /tmp/composer-setup.php
  fi
  (cd "$APP_DIR" && COMPOSER_ALLOW_SUPERUSER=1 "$PHP_BIN" "$(command -v composer)" install --no-dev --no-interaction --prefer-dist --optimize-autoloader)
fi
chown -R "$APP_USER:$APP_USER" "$APP_DIR"
chmod -R g+rX "$APP_DIR"

# ------------------------------------------------------------------ services: MySQL, Redis, Qdrant
existing() { [ -f "$ENV_FILE" ] && sed -n "s/^$1=//p" "$ENV_FILE" | tail -n1 | sed 's/^"//; s/"$//'; }

if [ -f "$ENV_FILE" ]; then
  DB_PASSWORD="${DB_PASSWORD:-$(existing DB_PASSWORD)}"
  REDIS_PASSWORD="${REDIS_PASSWORD:-$(existing REDIS_PASSWORD)}"
  QDRANT_API_KEY="$(existing QDRANT_API_KEY)"
fi
DB_HOST="${DB_HOST:-127.0.0.1}"
REDIS_HOST="${REDIS_HOST:-127.0.0.1}"
DB_PASSWORD="${DB_PASSWORD:-$(random_alnum 32)}"
REDIS_PASSWORD="${REDIS_PASSWORD:-$(random_alnum 32)}"
QDRANT_API_KEY="${QDRANT_API_KEY:-$(random_alnum 32)}"

if [ "$INSTALL_MYSQL" = 1 ]; then
  info "Configuring MySQL"
  systemctl enable --now "$MYSQL_SERVICE"
  for _ in $(seq 1 30); do mysqladmin ping --silent 2>/dev/null && break; sleep 2; done
  mysql --protocol=socket -uroot <<SQL || die "Cannot log in to MySQL as root through the local socket. Create the database manually and re-run with --db-host 127.0.0.1 --db-password ..."
CREATE DATABASE IF NOT EXISTS \`$DB_NAME\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '$DB_USER'@'localhost' IDENTIFIED BY '$DB_PASSWORD';
CREATE USER IF NOT EXISTS '$DB_USER'@'127.0.0.1' IDENTIFIED BY '$DB_PASSWORD';
ALTER USER '$DB_USER'@'localhost' IDENTIFIED BY '$DB_PASSWORD';
ALTER USER '$DB_USER'@'127.0.0.1' IDENTIFIED BY '$DB_PASSWORD';
GRANT ALL PRIVILEGES ON \`$DB_NAME\`.* TO '$DB_USER'@'localhost';
GRANT ALL PRIVILEGES ON \`$DB_NAME\`.* TO '$DB_USER'@'127.0.0.1';
FLUSH PRIVILEGES;
SQL
fi

if [ "$INSTALL_REDIS" = 1 ]; then
  info "Configuring Redis ($REDIS_CONF)"
  [ -f "$REDIS_CONF" ] || die "Redis configuration not found."
  sed -i -E '/^[#[:space:]]*requirepass[[:space:]]/d' "$REDIS_CONF"
  printf '\n# TokenHub\nrequirepass %s\n' "$REDIS_PASSWORD" >>"$REDIS_CONF"
  grep -Eq '^bind ' "$REDIS_CONF" || printf 'bind 127.0.0.1 -::1\n' >>"$REDIS_CONF"
  systemctl enable "$REDIS_SERVICE" >/dev/null 2>&1 || true
  systemctl restart "$REDIS_SERVICE"
fi

if [ "$INSTALL_QDRANT" = 1 ]; then
  info "Installing Qdrant $QDRANT_VERSION"
  id qdrant >/dev/null 2>&1 || useradd --system --home-dir /var/lib/qdrant --shell /usr/sbin/nologin qdrant
  install -d -m 0750 -o qdrant -g qdrant /var/lib/qdrant /var/lib/qdrant/storage /var/lib/qdrant/snapshots
  install -d -m 0755 /etc/qdrant
  if [ ! -x /usr/local/bin/qdrant ] || ! /usr/local/bin/qdrant --version 2>/dev/null | grep -q "$QDRANT_VERSION"; then
    archive="$QDRANT_ARCHIVE"
    if [ -z "$archive" ]; then
      archive="/tmp/qdrant-$QDRANT_VERSION.tar.gz"
      curl -fL "https://github.com/qdrant/qdrant/releases/download/v$QDRANT_VERSION/qdrant-$ARCH-unknown-linux-musl.tar.gz" -o "$archive" \
        || die "Download of Qdrant failed. Pass --qdrant-archive FILE, --qdrant-url URL or --vector-store mysql_fallback."
    fi
    tar -xzf "$archive" -C /usr/local/bin qdrant
    chmod 0755 /usr/local/bin/qdrant
  fi
  cat >/etc/qdrant/config.yaml <<YAML
storage:
  storage_path: /var/lib/qdrant/storage
  snapshots_path: /var/lib/qdrant/snapshots
service:
  host: 127.0.0.1
  http_port: 6333
  grpc_port: 6334
  api_key: $QDRANT_API_KEY
telemetry_disabled: true
YAML
  chown root:qdrant /etc/qdrant/config.yaml
  chmod 0640 /etc/qdrant/config.yaml
  install -m 0644 "$SOURCE_DIR/deploy/linux/systemd/qdrant.service" /etc/systemd/system/qdrant.service
  systemctl daemon-reload
  systemctl enable qdrant >/dev/null
  systemctl restart qdrant
  QDRANT_URL=http://127.0.0.1:6333
fi

# ------------------------------------------------------------------ configuration
if [ ! -f "$ENV_FILE" ]; then
  info "Writing $ENV_FILE"
  if [ -z "$SITE" ] && [ "$ASSUME_YES" = 0 ] && [ -t 0 ]; then
    read -r -p 'Domain or IP users will open (https://IP for HTTPS by IP, empty for plain HTTP): ' SITE
  fi
  HOST_IP="$(hostname -I 2>/dev/null | awk '{print $1}')"
  env_args=(--target=native --output="$ENV_FILE" --site="$SITE" --host="${HOST_IP:-localhost}"
    --admin-user="$ADMIN_USER" --db-host="$DB_HOST" --db-port="$DB_PORT" --db-name="$DB_NAME" --db-user="$DB_USER"
    --db-password="$DB_PASSWORD" --redis-host="$REDIS_HOST" --redis-port="$REDIS_PORT" --redis-password="$REDIS_PASSWORD"
    --vector-store="$VECTOR" --qdrant-url="${QDRANT_URL:-http://127.0.0.1:6333}" --qdrant-api-key="$QDRANT_API_KEY")
  [ -n "$ADMIN_PASSWORD" ] && env_args+=(--admin-password="$ADMIN_PASSWORD")
  "$PHP_BIN" "$APP_DIR/bin/install.php" env "${env_args[@]}" --template="$APP_DIR/.env.example"
fi
# Installations from before the realtime gateway have no shared internal token yet.
realtime_token="$(sed -n 's/^REALTIME_INTERNAL_TOKEN=//p' "$ENV_FILE" | tail -n1 | sed 's/^"//; s/"$//')"
if [ "${#realtime_token}" -lt 24 ] || [ "$realtime_token" = change-me ]; then
  sed -i '/^REALTIME_INTERNAL_TOKEN=/d' "$ENV_FILE"
  printf 'REALTIME_INTERNAL_TOKEN=%s\n' "$(random_alnum 48)" >>"$ENV_FILE"
  info "Generated REALTIME_INTERNAL_TOKEN in $ENV_FILE"
fi
chown root:"$APP_USER" "$ENV_FILE"
chmod 0640 "$ENV_FILE"
ln -sfn "$ENV_FILE" "$APP_DIR/.env"

env_value() { sed -n "s/^$1=//p" "$ENV_FILE" | tail -n1 | sed 's/^"//; s/"$//'; }
cat >"$CONFIG_DIR/caddy.env" <<CADDYENV
CADDY_ADMIN_API=localhost:2019
TOKENHUB_SITE_ADDRESS=$(env_value TOKENHUB_SITE_ADDRESS)
TOKENHUB_CADDY_TLS=$(env_value TOKENHUB_CADDY_TLS)
TOKENHUB_CADDY_GLOBAL=$(env_value TOKENHUB_CADDY_GLOBAL)
TOKENHUB_APP_DIR=$APP_DIR
TOKENHUB_PHP_FASTCGI=unix/$FPM_SOCKET
TOKENHUB_TLS_FRAGMENT=/var/lib/tokenhub/caddy/tls-current.caddy
TOKENHUB_LOG_PATH=/var/log/tokenhub/caddy-access.log
CADDYENV
chmod 0644 "$CONFIG_DIR/caddy.env"

# ------------------------------------------------------------------ PHP-FPM and Caddy
info "Configuring PHP-FPM pool"
install -d "$(dirname "$FPM_SOCKET")"
cat >"$FPM_POOL_DIR/tokenhub.conf" <<FPM
[tokenhub]
user = $APP_USER
group = $APP_USER
listen = $FPM_SOCKET
listen.owner = $APP_USER
listen.group = $APP_USER
listen.mode = 0660
pm = dynamic
pm.max_children = 32
pm.start_servers = 4
pm.min_spare_servers = 2
pm.max_spare_servers = 8
pm.max_requests = 1000
request_terminate_timeout = 0
clear_env = no
php_admin_value[expose_php] = Off
php_admin_value[upload_max_filesize] = 32M
php_admin_value[post_max_size] = 34M
php_admin_value[memory_limit] = 512M
php_admin_value[error_log] = /var/log/tokenhub/php-fpm-error.log
FPM
systemctl enable "$FPM_SERVICE" >/dev/null
systemctl restart "$FPM_SERVICE"

info "Configuring Caddy"
install -m 0644 "$APP_DIR/Caddyfile.example" /etc/caddy/Caddyfile
install -d /etc/systemd/system/caddy.service.d
install -m 0644 "$SOURCE_DIR/deploy/linux/systemd/caddy-tokenhub.conf" /etc/systemd/system/caddy.service.d/tokenhub.conf
cat >/etc/sudoers.d/tokenhub-caddy <<SUDO
# Allows the certificate center in the admin console to reload Caddy.
$APP_USER ALL=(root) NOPASSWD: /usr/bin/systemctl reload caddy, /bin/systemctl reload caddy
SUDO
chmod 0440 /etc/sudoers.d/tokenhub-caddy

if command -v getenforce >/dev/null 2>&1 && [ "$(getenforce)" = Enforcing ]; then
  info "Applying SELinux settings"
  setsebool -P httpd_can_network_connect 1 || true
  semanage fcontext -a -t httpd_sys_rw_content_t "$APP_DIR/storage(/.*)?" 2>/dev/null || true
  semanage fcontext -a -t httpd_sys_rw_content_t "/var/lib/tokenhub(/.*)?" 2>/dev/null || true
  semanage fcontext -a -t httpd_log_t "/var/log/tokenhub(/.*)?" 2>/dev/null || true
  restorecon -R "$APP_DIR" /var/lib/tokenhub /var/log/tokenhub || true
fi

# ------------------------------------------------------------------ RAG worker (Python)
if [ "$VECTOR" = qdrant ]; then
  info "Installing the RAG worker"
  PYTHON_BIN=""
  for candidate in python3.13 python3.12 python3.11 python3.10 python3.9 python3; do
    if command -v "$candidate" >/dev/null 2>&1 && "$candidate" -c 'import sys; sys.exit(0 if sys.version_info >= (3, 9) else 1)'; then
      PYTHON_BIN="$(command -v "$candidate")"; break
    fi
  done
  [ -n "$PYTHON_BIN" ] || die "Python 3.9+ is required for the RAG worker (or use --vector-store mysql_fallback)."
  install -d -m 0755 "$RAG_DIR"
  rsync -a --delete "$SOURCE_DIR/python-rag-worker/" "$RAG_DIR/src/"
  [ -x "$RAG_DIR/venv/bin/python" ] || "$PYTHON_BIN" -m venv "$RAG_DIR/venv"
  "$RAG_DIR/venv/bin/pip" install -q --upgrade pip
  "$RAG_DIR/venv/bin/pip" install -q -r "$RAG_DIR/src/requirements.txt"
  rm -rf "$RAG_DIR/tokenhub_rag_worker"
  cp -a "$RAG_DIR/src/tokenhub_rag_worker" "$RAG_DIR/tokenhub_rag_worker"
fi

# ------------------------------------------------------------------ database and first administrator
info "Running the TokenHub installer"
sudo -u "$APP_USER" "$PHP_BIN" "$APP_DIR/bin/install.php" run --non-interactive --mode=native --wait=60

# ------------------------------------------------------------------ systemd services
info "Enabling services"
render_unit() {
  sed -e "s#@APP_DIR@#$APP_DIR#g" -e "s#@APP_USER@#$APP_USER#g" -e "s#@PHP_BIN@#$PHP_BIN#g" -e "s#@RAG_DIR@#$RAG_DIR#g" \
    "$SOURCE_DIR/deploy/linux/systemd/$1" >"/etc/systemd/system/$1"
}
render_unit 'tokenhub-worker@.service'
render_unit tokenhub-scheduler.service
[ "$VECTOR" = qdrant ] && render_unit tokenhub-rag-worker.service
systemctl daemon-reload
for i in $(seq 1 "$WORKERS"); do systemctl enable "tokenhub-worker@$i" >/dev/null; systemctl restart "tokenhub-worker@$i"; done
systemctl enable tokenhub-scheduler >/dev/null && systemctl restart tokenhub-scheduler
if [ "$VECTOR" = qdrant ]; then systemctl enable tokenhub-rag-worker >/dev/null && systemctl restart tokenhub-rag-worker; fi
# Realtime gateway (Go, optional): built when a Go toolchain is available.
if command -v go >/dev/null 2>&1; then
  info "Building the realtime gateway"
  (cd "$SOURCE_DIR/realtime-gateway" && CGO_ENABLED=0 go build -trimpath -ldflags="-s -w" -o "$APP_DIR/bin/realtime-gateway" .) \
    && render_unit tokenhub-realtime.service && systemctl daemon-reload \
    && systemctl enable tokenhub-realtime >/dev/null && systemctl restart tokenhub-realtime \
    || warn "Realtime gateway build failed; /v1/realtime stays unavailable"
else
  warn "Go not found: realtime speech (/v1/realtime) is not installed. Install Go 1.22+ and rerun to enable it."
fi
systemctl enable caddy >/dev/null
systemctl restart caddy

if [ "$OPEN_FIREWALL" = 1 ]; then
  if command -v firewall-cmd >/dev/null 2>&1 && firewall-cmd --state >/dev/null 2>&1; then
    firewall-cmd --permanent --add-service=http --add-service=https >/dev/null && firewall-cmd --reload >/dev/null
  elif command -v ufw >/dev/null 2>&1 && ufw status | grep -q 'Status: active'; then
    ufw allow 80/tcp >/dev/null; ufw allow 443/tcp >/dev/null
  fi
fi

# logrotate for TokenHub logs
cat >/etc/logrotate.d/tokenhub <<ROTATE
/var/log/tokenhub/*.log /var/log/tokenhub/*.jsonl {
    daily
    rotate 14
    compress
    delaycompress
    missingok
    notifempty
    copytruncate
}
ROTATE

info "Self-check"
sleep 3
sudo -u "$APP_USER" "$PHP_BIN" "$APP_DIR/bin/doctor.php" || warn "Some checks reported problems (see above)."

APP_URL_NOW="$(env_value APP_URL)"
cat <<TXT

TokenHub is installed.
  Admin console : ${APP_URL_NOW%/}/admin/
  API base URL  : ${APP_URL_NOW%/}/v1
  Administrator : $(env_value TOKENHUB_ADMIN_USERNAME)
  Password      : $(env_value TOKENHUB_ADMIN_PASSWORD)
  Configuration : $ENV_FILE   (back it up: APP_KEY decrypts stored provider secrets)

After the first login change the password and remove TOKENHUB_ADMIN_PASSWORD from $ENV_FILE.
Backups: sudo $APP_DIR/scripts/backup-tokenhub.sh (see docs/deployment/backup-restore.md)
TXT
