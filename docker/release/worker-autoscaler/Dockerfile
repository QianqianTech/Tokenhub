FROM docker:27-cli

RUN apk add --no-cache redis

WORKDIR /srv/app

COPY scripts/worker-autoscaler.sh /usr/local/bin/worker-autoscaler
COPY docker-compose.offline.yml /srv/app/docker-compose.offline.yml

RUN chmod +x /usr/local/bin/worker-autoscaler

CMD ["/usr/local/bin/worker-autoscaler"]
