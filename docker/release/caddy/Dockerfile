ARG CADDY_VERSION=2.10
# Pinned by digest through scripts/release/pin-base-images.sh (docker/release/base-images.env).
ARG CADDY_BASE_IMAGE=caddy:${CADDY_VERSION}
FROM ${CADDY_BASE_IMAGE}

# Build id for the APP service worker cache (empty: VERSION + content hash).
ARG TOKENHUB_APP_BUILD=

COPY docker/release/caddy/Caddyfile /etc/caddy/Caddyfile
COPY docker/release/caddy/entrypoint.sh /usr/local/bin/tokenhub-caddy-entrypoint
COPY public /srv/app/public
COPY VERSION /srv/app/VERSION
COPY scripts/release/stamp-app-version.sh /tmp/stamp-app-version.sh

RUN sh /tmp/stamp-app-version.sh /srv/app "${TOKENHUB_APP_BUILD}" \
    && rm -f /tmp/stamp-app-version.sh \
    && chmod +x /usr/local/bin/tokenhub-caddy-entrypoint \
    && touch /etc/caddy/tokenhub-http-redirect.caddy

ENV TOKENHUB_SITE_ADDRESS=:80 \
    TOKENHUB_CADDY_TLS="" \
    TOKENHUB_CADDY_GLOBAL=""

HEALTHCHECK --interval=15s --timeout=5s --retries=5 CMD wget -q -O /dev/null http://127.0.0.1:8081/caddy-health || exit 1

ENTRYPOINT ["tokenhub-caddy-entrypoint"]
