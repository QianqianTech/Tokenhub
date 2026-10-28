FROM caddy:2

COPY docker/release/caddy/Caddyfile /etc/caddy/Caddyfile
COPY docker/release/caddy/entrypoint.sh /usr/local/bin/tokenhub-caddy-entrypoint
COPY public /srv/app/public

RUN chmod +x /usr/local/bin/tokenhub-caddy-entrypoint

ENTRYPOINT ["tokenhub-caddy-entrypoint"]
