FROM docker:27-cli

RUN apk add --no-cache redis

WORKDIR /srv/app

# deploy/docker/compose.yml mounts compose.yml and .env into /srv/app.
COPY scripts/worker-autoscaler.sh /usr/local/bin/worker-autoscaler

RUN chmod +x /usr/local/bin/worker-autoscaler

CMD ["/usr/local/bin/worker-autoscaler"]
