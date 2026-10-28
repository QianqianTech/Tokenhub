# TokenHub realtime gateway (Go WebSocket relay, standard library only).
ARG GO_IMAGE=golang:1.24-alpine
ARG RUNTIME_IMAGE=alpine:3.20

FROM ${GO_IMAGE} AS build
WORKDIR /src
COPY realtime-gateway/ ./
RUN CGO_ENABLED=0 go test ./... && CGO_ENABLED=0 go build -trimpath -ldflags="-s -w" -o /out/realtime-gateway .

FROM ${RUNTIME_IMAGE}
RUN adduser -D -u 10001 tokenhub && apk add --no-cache ca-certificates wget
COPY --from=build /out/realtime-gateway /usr/local/bin/realtime-gateway
USER tokenhub
EXPOSE 8090
HEALTHCHECK --interval=15s --timeout=3s --retries=5 CMD wget -qO- http://127.0.0.1:8090/healthz >/dev/null || exit 1
ENTRYPOINT ["/usr/local/bin/realtime-gateway"]
