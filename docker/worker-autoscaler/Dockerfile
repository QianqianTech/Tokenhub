FROM docker:27-cli

RUN apk add --no-cache redis

WORKDIR /srv/app

CMD ["sh", "scripts/worker-autoscaler.sh"]
