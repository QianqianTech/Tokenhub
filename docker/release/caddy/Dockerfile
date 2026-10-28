ARG CADDY_VERSION=2.10
FROM caddy:${CADDY_VERSION}

COPY docker/release/caddy/Caddyfile /etc/caddy/Caddyfile
COPY docker/release/caddy/entrypoint.sh /usr/local/bin/tokenhub-caddy-entrypoint
COPY public /srv/app/public

RUN chmod +x /usr/local/bin/tokenhub-caddy-entrypoint

ENV TOKENHUB_SITE_ADDRESS=:80 \
    TOKENHUB_CADDY_TLS="" \
    TOKENHUB_CADDY_GLOBAL=""

HEALTHCHECK --interval=15s --timeout=5s --retries=5 CMD wget -q -O /dev/null http://127.0.0.1:8081/caddy-health || exit 1

ENTRYPOINT ["tokenhub-caddy-entrypoint"]
