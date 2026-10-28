// TokenHub realtime gateway (MM-030).
//
// A WebSocket relay for realtime speech models (OpenAI Realtime protocol and
// compatible endpoints such as Qwen-Omni realtime and ElevenLabs realtime;
// Gemini Live via a URL template). PHP-FPM cannot hold WebSocket
// connections, so this small Go service does it and delegates everything
// that needs TokenHub state to TokenHub's internal API:
//
//	POST {TOKENHUB_INTERNAL_URL}/internal/realtime/sessions  authorize the client key, pick the channel
//	POST {TOKENHUB_INTERNAL_URL}/internal/realtime/usage     record usage per completed response
//
// Clients connect to GET /v1/realtime?model=<TokenHub model> with their
// TokenHub API key (Authorization: Bearer, x-api-key, the browser
// subprotocol "openai-insecure-api-key.<key>", or ?key=) or with a
// short-lived client secret issued by POST /v1/realtime/client_secrets.
package main

import (
	"bytes"
	"context"
	"encoding/json"
	"errors"
	"io"
	"log"
	"net"
	"net/http"
	"os"
	"os/signal"
	"strconv"
	"strings"
	"sync"
	"syscall"
	"time"
)

type config struct {
	listen        string
	internalURL   string
	internalToken string
	maxMessage    int64
	dialTimeout   time.Duration
	idleTimeout   time.Duration
	maxSession    time.Duration
	trusted       []*net.IPNet // proxies whose X-Forwarded-For is believed
}

func loadConfig() config {
	return config{
		listen:        envOr("REALTIME_LISTEN_ADDR", ":8090"),
		internalURL:   strings.TrimRight(envOr("TOKENHUB_INTERNAL_URL", "http://caddy"), "/"),
		internalToken: os.Getenv("REALTIME_INTERNAL_TOKEN"),
		maxMessage:    int64(envInt("REALTIME_MAX_MESSAGE_BYTES", 16<<20)),
		dialTimeout:   time.Duration(envInt("REALTIME_DIAL_TIMEOUT_SECONDS", 15)) * time.Second,
		idleTimeout:   time.Duration(envInt("REALTIME_IDLE_TIMEOUT_SECONDS", 600)) * time.Second,
		maxSession:    time.Duration(envInt("REALTIME_MAX_SESSION_SECONDS", 3600)) * time.Second,
		trusted:       parseCIDRs(envOr("REALTIME_TRUSTED_PROXIES", defaultTrustedProxies)),
	}
}

// defaultTrustedProxies covers the Caddy hop in the bundled deployments
// (loopback for systemd, private ranges for the compose network).
const defaultTrustedProxies = "127.0.0.0/8,::1/128,10.0.0.0/8,172.16.0.0/12,192.168.0.0/16,fc00::/7"

func parseCIDRs(list string) []*net.IPNet {
	var nets []*net.IPNet
	for _, item := range strings.Split(list, ",") {
		item = strings.TrimSpace(item)
		if item == "" {
			continue
		}
		if !strings.Contains(item, "/") {
			if ip := net.ParseIP(item); ip != nil && ip.To4() != nil {
				item += "/32"
			} else {
				item += "/128"
			}
		}
		if _, n, err := net.ParseCIDR(item); err == nil {
			nets = append(nets, n)
		} else {
			log.Printf("ignoring invalid REALTIME_TRUSTED_PROXIES entry %q", item)
		}
	}
	return nets
}

func main() {
	cfg := loadConfig()
	if cfg.internalToken == "" {
		log.Fatal("REALTIME_INTERNAL_TOKEN is required (shared with TokenHub)")
	}
	gw := newGateway(cfg, &http.Client{Timeout: 15 * time.Second})
	mux := http.NewServeMux()
	mux.HandleFunc("/v1/realtime", gw.handleRealtime)
	mux.HandleFunc("/healthz", func(w http.ResponseWriter, _ *http.Request) {
		w.WriteHeader(http.StatusOK)
		_, _ = w.Write([]byte("ok"))
	})
	server := &http.Server{Addr: cfg.listen, Handler: mux, ReadHeaderTimeout: 10 * time.Second}

	go func() {
		log.Printf("realtime gateway listening on %s", cfg.listen)
		if err := server.ListenAndServe(); err != nil && !errors.Is(err, http.ErrServerClosed) {
			log.Fatal(err)
		}
	}()
	stop := make(chan os.Signal, 1)
	signal.Notify(stop, syscall.SIGINT, syscall.SIGTERM)
	<-stop
	ctx, cancel := context.WithTimeout(context.Background(), 10*time.Second)
	defer cancel()
	_ = server.Shutdown(ctx)
	gw.wait()
}

type gateway struct {
	cfg    config
	client *http.Client
	active sync.WaitGroup
}

func newGateway(cfg config, client *http.Client) *gateway {
	return &gateway{cfg: cfg, client: client}
}

func (g *gateway) wait() { g.active.Wait() }

// session is TokenHub's answer to an authorization request.
type session struct {
	SessionID         string            `json:"session_id"`
	UpstreamURL       string            `json:"upstream_url"`
	UpstreamHeaders   map[string]string `json:"upstream_headers"`
	UsageProtocol     string            `json:"usage_protocol"`
	MaxSessionSeconds int               `json:"max_session_seconds"`
}

type apiEnvelope struct {
	OK    bool            `json:"ok"`
	Data  json.RawMessage `json:"data"`
	Error *struct {
		Code    string `json:"code"`
		Message string `json:"message"`
	} `json:"error"`
}

func (g *gateway) handleRealtime(w http.ResponseWriter, r *http.Request) {
	// Reject a malformed handshake before authorizing: authorization reserves
	// quota and a concurrency slot that only a final usage report releases.
	if status, message := CheckUpgrade(r); status != 0 {
		if status == http.StatusUpgradeRequired {
			w.Header().Set("Sec-WebSocket-Version", "13")
		}
		writeError(w, status, "REALTIME_BAD_HANDSHAKE", message)
		return
	}
	key, protocols := clientKey(r)
	if key == "" {
		writeError(w, http.StatusUnauthorized, "API_KEY_INVALID", "API key required")
		return
	}
	model := r.URL.Query().Get("model")
	sess, status, err := g.authorize(r.Context(), key, model, clientIP(r, g.cfg.trusted))
	if err != nil {
		writeError(w, status, "REALTIME_REJECTED", err.Error())
		return
	}

	headers := http.Header{}
	for name, value := range sess.UpstreamHeaders {
		headers.Set(name, value)
	}
	upstream, _, err := Dial(sess.UpstreamURL, headers, g.cfg.dialTimeout, g.cfg.maxMessage)
	if err != nil {
		log.Printf("session %s: upstream dial failed: %v", sess.SessionID, err)
		g.report(sess, usageTotals{}, "failed", "PROVIDER_UNAVAILABLE", 0, true)
		writeError(w, http.StatusBadGateway, "PROVIDER_UNAVAILABLE", "Realtime upstream is not reachable")
		return
	}

	selected := ""
	for _, p := range protocols {
		if p == "realtime" {
			selected = p
		}
	}
	client, err := Upgrade(w, r, selected, g.cfg.maxMessage)
	if err != nil {
		upstream.Close(1011, "client upgrade failed")
		g.report(sess, usageTotals{}, "failed", "CLIENT_UPGRADE_FAILED", 0, true)
		return
	}

	g.active.Add(1)
	defer g.active.Done()
	maxSession := g.cfg.maxSession
	if sess.MaxSessionSeconds > 0 {
		maxSession = time.Duration(sess.MaxSessionSeconds) * time.Second
	}
	g.relay(client, upstream, sess, maxSession)
}

// relay pumps messages both ways until either side closes, the session
// exceeds its maximum duration, or TokenHub says the quota is exhausted.
func (g *gateway) relay(client, upstream *Conn, sess session, maxSession time.Duration) {
	started := time.Now()
	done := make(chan struct{})
	var once sync.Once
	finish := func() { once.Do(func() { close(done) }) }
	parser := newUsageParser(sess.UsageProtocol)
	activity := make(chan struct{}, 1)
	touch := func() {
		select {
		case activity <- struct{}{}:
		default:
		}
	}

	go func() { // client -> upstream
		defer finish()
		for {
			kind, msg, err := client.ReadMessage()
			if err != nil {
				return
			}
			touch()
			if err := upstream.WriteMessage(kind, msg); err != nil {
				return
			}
		}
	}()

	var quotaExceeded bool
	go func() { // upstream -> client, usage observed on the way
		defer finish()
		for {
			kind, msg, err := upstream.ReadMessage()
			if err != nil {
				return
			}
			touch()
			if err := client.WriteMessage(kind, msg); err != nil {
				return
			}
			if kind == opText {
				if usage, ok := parser.observe(msg); ok {
					if allowed := g.report(sess, usage, "success", "", time.Since(started).Milliseconds(), false); !allowed {
						quotaExceeded = true
						return
					}
				}
			}
		}
	}()

	timer := time.NewTimer(maxSession)
	defer timer.Stop()
	idle := time.NewTimer(g.cfg.idleTimeout)
	defer idle.Stop()
loop:
	for {
		select {
		case <-done:
			break loop
		case <-timer.C:
			break loop
		case <-idle.C:
			break loop
		case <-activity:
			if !idle.Stop() {
				select {
				case <-idle.C:
				default:
				}
			}
			idle.Reset(g.cfg.idleTimeout)
		}
	}
	reason := "session ended"
	code := 1000
	if quotaExceeded {
		code, reason = 4002, "quota exceeded"
	}
	client.Close(code, reason)
	upstream.Close(1000, "client gone")
	if pending, ok := parser.flush(); ok {
		g.report(sess, pending, "success", "", time.Since(started).Milliseconds(), true)
	} else {
		g.report(sess, usageTotals{}, "success", "", time.Since(started).Milliseconds(), true)
	}
}

func (g *gateway) authorize(ctx context.Context, key, model, ip string) (session, int, error) {
	body, _ := json.Marshal(map[string]string{"api_key": key, "model": model, "client_ip": ip})
	req, err := http.NewRequestWithContext(ctx, http.MethodPost, g.cfg.internalURL+"/internal/realtime/sessions", bytes.NewReader(body))
	if err != nil {
		return session{}, http.StatusInternalServerError, err
	}
	req.Header.Set("Content-Type", "application/json")
	req.Header.Set("X-TokenHub-Internal-Token", g.cfg.internalToken)
	resp, err := g.client.Do(req)
	if err != nil {
		return session{}, http.StatusBadGateway, errors.New("TokenHub is not reachable")
	}
	defer resp.Body.Close()
	var envelope apiEnvelope
	if err := json.NewDecoder(io.LimitReader(resp.Body, 1<<20)).Decode(&envelope); err != nil {
		return session{}, http.StatusBadGateway, errors.New("invalid TokenHub response")
	}
	if !envelope.OK || resp.StatusCode >= 400 {
		message := "request rejected"
		if envelope.Error != nil {
			message = envelope.Error.Code + ": " + envelope.Error.Message
		}
		status := resp.StatusCode
		if status < 400 {
			status = http.StatusForbidden
		}
		return session{}, status, errors.New(message)
	}
	var sess session
	if err := json.Unmarshal(envelope.Data, &sess); err != nil || sess.UpstreamURL == "" {
		return session{}, http.StatusBadGateway, errors.New("invalid TokenHub session")
	}
	return sess, http.StatusOK, nil
}

// report sends usage to TokenHub; the answer says whether the session may continue.
func (g *gateway) report(sess session, usage usageTotals, status, errorCode string, durationMs int64, final bool) bool {
	if sess.SessionID == "" {
		return true
	}
	body, _ := json.Marshal(map[string]any{
		"session_id":  sess.SessionID,
		"usage":       usage,
		"status":      status,
		"error_code":  errorCode,
		"duration_ms": durationMs,
		"final":       final,
	})
	req, err := http.NewRequest(http.MethodPost, g.cfg.internalURL+"/internal/realtime/usage", bytes.NewReader(body))
	if err != nil {
		return true
	}
	req.Header.Set("Content-Type", "application/json")
	req.Header.Set("X-TokenHub-Internal-Token", g.cfg.internalToken)
	resp, err := g.client.Do(req)
	if err != nil {
		log.Printf("session %s: usage report failed: %v", sess.SessionID, err)
		return true
	}
	defer resp.Body.Close()
	var envelope struct {
		OK   bool `json:"ok"`
		Data struct {
			Allowed *bool `json:"allowed"`
		} `json:"data"`
	}
	_ = json.NewDecoder(io.LimitReader(resp.Body, 1<<16)).Decode(&envelope)
	if envelope.Data.Allowed != nil {
		return *envelope.Data.Allowed
	}
	return true
}

// clientKey finds the TokenHub key and the offered subprotocols.
func clientKey(r *http.Request) (string, []string) {
	var protocols []string
	key := ""
	for _, value := range r.Header.Values("Sec-WebSocket-Protocol") {
		for _, p := range strings.Split(value, ",") {
			p = strings.TrimSpace(p)
			if p == "" {
				continue
			}
			protocols = append(protocols, p)
			if strings.HasPrefix(p, "openai-insecure-api-key.") {
				key = strings.TrimPrefix(p, "openai-insecure-api-key.")
			}
		}
	}
	if auth := r.Header.Get("Authorization"); strings.HasPrefix(strings.ToLower(auth), "bearer ") {
		key = strings.TrimSpace(auth[7:])
	} else if v := r.Header.Get("x-api-key"); v != "" {
		key = v
	} else if key == "" {
		key = r.URL.Query().Get("key")
	}
	return key, protocols
}

// clientIP is the address TokenHub checks API-key IP allowlists against.
// When the direct peer is a trusted proxy (Caddy), X-Forwarded-For is walked
// from the right, skipping further trusted hops; the first untrusted address
// is the client. Entries left of it were supplied by the client and ignored.
func clientIP(r *http.Request, trusted []*net.IPNet) string {
	host, _, err := net.SplitHostPort(r.RemoteAddr)
	if err != nil {
		host = r.RemoteAddr
	}
	if !ipTrusted(host, trusted) {
		return host
	}
	var hops []string
	for _, value := range r.Header.Values("X-Forwarded-For") {
		for _, part := range strings.Split(value, ",") {
			if part = strings.TrimSpace(part); part != "" {
				hops = append(hops, part)
			}
		}
	}
	for i := len(hops) - 1; i >= 0; i-- {
		ip := net.ParseIP(hops[i])
		if ip == nil {
			break // malformed: stop at the last address we could trust
		}
		host = ip.String()
		if !ipTrusted(host, trusted) {
			return host
		}
	}
	return host
}

func ipTrusted(addr string, trusted []*net.IPNet) bool {
	ip := net.ParseIP(addr)
	if ip == nil {
		return false
	}
	for _, n := range trusted {
		if n.Contains(ip) {
			return true
		}
	}
	return false
}

func writeError(w http.ResponseWriter, status int, code, message string) {
	w.Header().Set("Content-Type", "application/json")
	w.WriteHeader(status)
	_ = json.NewEncoder(w).Encode(map[string]any{"ok": false, "error": map[string]string{"code": code, "message": message}})
}

func envOr(name, fallback string) string {
	if v := os.Getenv(name); v != "" {
		return v
	}
	return fallback
}

func envInt(name string, fallback int) int {
	if v, err := strconv.Atoi(os.Getenv(name)); err == nil && v > 0 {
		return v
	}
	return fallback
}
