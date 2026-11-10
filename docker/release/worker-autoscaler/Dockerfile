# Pinned by digest through scripts/release/pin-base-images.sh (docker/release/base-images.env).
ARG BASE_IMAGE_PREFIX=
ARG DOCKER_CLI_BASE_IMAGE=${BASE_IMAGE_PREFIX}docker:27-cli
FROM ${DOCKER_CLI_BASE_IMAGE}

RUN apk add --no-cache redis

WORKDIR /srv/app

# deploy/docker/compose.yml mounts compose.yml and .env into /srv/app.
COPY scripts/worker-autoscaler.sh /usr/local/bin/worker-autoscaler

RUN chmod +x /usr/local/bin/worker-autoscaler

CMD ["/usr/local/bin/worker-autoscaler"]
