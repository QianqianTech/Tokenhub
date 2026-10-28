package main

import (
	"bufio"
	"encoding/json"
	"io"
	"net"
	"net/http"
	"net/http/httptest"
	"strings"
	"sync"
	"testing"
	"time"
)

func bufioReader(c net.Conn) *bufio.Reader { return bufio.NewReader(c) }

func TestAcceptKeyRFCExample(t *testing.T) {
	if got := AcceptKey("dGhlIHNhbXBsZSBub25jZQ=="); got != "s3pPLMBiTxaQ9kYGzzhZRbK+xOo=" {
		t.Fatalf("accept key = %s", got)
	}
}

func TestOpenAIUsageParser(t *testing.T) {
	p := newUsageParser("openai")
	if _, ok := p.observe([]byte(`{"type":"response.audio.delta","delta":"AAA"}`)); ok {
		t.Fatal("deltas carry no usage")
	}
	u, ok := p.observe([]byte(`{"type":"response.done","response":{"usage":{"input_tokens":120,"output_tokens":80,"input_token_details":{"cached_tokens":20,"audio_tokens":90},"output_token_details":{"audio_tokens":70}}}}`))
	if !ok || u.InputTokens != 120 || u.OutputTokens != 80 || u.CacheReadTokens != 20 || u.AudioInputTokens != 90 || u.AudioOutputTokens != 70 {
		t.Fatalf("unexpected usage %+v", u)
	}
	u, ok = p.observe([]byte(`{"type":"conversation.item.input_audio_transcription.completed","usage":{"type":"duration","seconds":2.2}}`))
	if !ok || u.AudioSeconds != 3 {
		t.Fatalf("transcription usage %+v", u)
	}
}

func TestGeminiUsageParser(t *testing.T) {
	p := newUsageParser("gemini")
	if _, ok := p.observe([]byte(`{"usageMetadata":{"promptTokenCount":50,"responseTokenCount":30,"promptTokensDetails":[{"modality":"AUDIO","tokenCount":40}]}}`)); ok {
		t.Fatal("usage is reported at turn end")
	}
	u, ok := p.observe([]byte(`{"serverContent":{"turnComplete":true}}`))
	if !ok || u.InputTokens != 50 || u.OutputTokens != 30 || u.AudioInputTokens != 40 {
		t.Fatalf("gemini usage %+v", u)
	}
}

// End to end: client -> gateway -> fake upstream, with a fake TokenHub
// internal API that authorizes and records usage.
func TestRelayEndToEnd(t *testing.T) {
	upstream := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.Header.Get("Authorization") != "Bearer upstream-secret" {
			http.Error(w, "bad upstream key", http.StatusUnauthorized)
			return
		}
		conn, err := Upgrade(w, r, "", 1<<20)
		if err != nil {
			return
		}
		defer conn.Close(1000, "bye")
		for {
			_, msg, err := conn.ReadMessage()
			if err != nil {
				return
			}
			var event map[string]any
			_ = json.Unmarshal(msg, &event)
			if event["type"] == "response.create" {
				_ = conn.WriteMessage(opText, []byte(`{"type":"response.output_audio.delta","delta":"`+strings.Repeat("A", 70000)+`"}`))
				_ = conn.WriteMessage(opText, []byte(`{"type":"response.done","response":{"usage":{"input_tokens":10,"output_tokens":5}}}`))
			}
		}
	}))
	defer upstream.Close()

	var mu sync.Mutex
	var reports []map[string]any
	authorizations := 0
	tokenhub := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.Header.Get("X-TokenHub-Internal-Token") != "internal" {
			http.Error(w, "forbidden", http.StatusForbidden)
			return
		}
		body, _ := io.ReadAll(r.Body)
		var payload map[string]any
		_ = json.Unmarshal(body, &payload)
		w.Header().Set("Content-Type", "application/json")
		switch r.URL.Path {
		case "/internal/realtime/sessions":
			mu.Lock()
			authorizations++
			mu.Unlock()
			if payload["api_key"] != "th-key" || payload["model"] != "gpt-realtime" {
				w.WriteHeader(http.StatusForbidden)
				_, _ = w.Write([]byte(`{"ok":false,"error":{"code":"MODEL_FORBIDDEN","message":"no"}}`))
				return
			}
			_, _ = w.Write([]byte(`{"ok":true,"data":{"session_id":"rt_1","upstream_url":"ws` + strings.TrimPrefix(upstream.URL, "http") + `/v1/realtime?model=gpt-realtime-2.1","upstream_headers":{"Authorization":"Bearer upstream-secret"},"usage_protocol":"openai"}}`))
		case "/internal/realtime/usage":
			mu.Lock()
			reports = append(reports, payload)
			mu.Unlock()
			_, _ = w.Write([]byte(`{"ok":true,"data":{"allowed":true}}`))
		}
	}))
	defer tokenhub.Close()

	gw := newGateway(config{internalURL: tokenhub.URL, internalToken: "internal", maxMessage: 1 << 20, dialTimeout: 5 * time.Second, idleTimeout: 5 * time.Second, maxSession: 10 * time.Second}, &http.Client{Timeout: 5 * time.Second})
	front := httptest.NewServer(http.HandlerFunc(gw.handleRealtime))
	defer front.Close()

	// Not a WebSocket handshake: rejected before TokenHub is asked to
	// authorize (which would reserve quota and a concurrency slot).
	resp, err := http.Get(front.URL + "/v1/realtime?model=gpt-realtime&key=th-key")
	if err != nil || resp.StatusCode != http.StatusBadRequest {
		t.Fatalf("plain GET should be 400, got %v %v", resp, err)
	}
	mu.Lock()
	if len(reports) != 0 || authorizations != 0 {
		t.Fatalf("bad handshake must not authorize: %d authorizations", authorizations)
	}
	mu.Unlock()

	// Missing key on a valid handshake: 401.
	req, _ := http.NewRequest(http.MethodGet, front.URL+"/v1/realtime?model=gpt-realtime", nil)
	req.Header.Set("Connection", "Upgrade")
	req.Header.Set("Upgrade", "websocket")
	req.Header.Set("Sec-WebSocket-Version", "13")
	req.Header.Set("Sec-WebSocket-Key", "dGhlIHNhbXBsZSBub25jZQ==")
	resp, err = http.DefaultClient.Do(req)
	if err != nil || resp.StatusCode != http.StatusUnauthorized {
		t.Fatalf("missing key should be 401, got %v %v", resp, err)
	}

	headers := http.Header{}
	headers.Set("Sec-WebSocket-Protocol", "realtime, openai-insecure-api-key.th-key")
	client, handshake, err := Dial("ws"+strings.TrimPrefix(front.URL, "http")+"/v1/realtime?model=gpt-realtime", headers, 5*time.Second, 1<<20)
	if err != nil {
		t.Fatalf("dial: %v", err)
	}
	if handshake.Header.Get("Sec-WebSocket-Protocol") != "realtime" {
		t.Fatalf("subprotocol not echoed: %q", handshake.Header.Get("Sec-WebSocket-Protocol"))
	}
	if err := client.WriteMessage(opText, []byte(`{"type":"response.create"}`)); err != nil {
		t.Fatal(err)
	}
	_, first, err := client.ReadMessage()
	if err != nil || len(first) < 70000 {
		t.Fatalf("large frame not relayed: %v %d", err, len(first))
	}
	_, done, err := client.ReadMessage()
	if err != nil || !strings.Contains(string(done), "response.done") {
		t.Fatalf("response.done not relayed: %v %s", err, done)
	}
	client.Close(1000, "done")
	deadline := time.Now().Add(3 * time.Second)
	for time.Now().Before(deadline) {
		mu.Lock()
		n := len(reports)
		mu.Unlock()
		if n >= 2 {
			break
		}
		time.Sleep(20 * time.Millisecond)
	}
	mu.Lock()
	defer mu.Unlock()
	if len(reports) < 2 {
		t.Fatalf("expected a usage report and a final report, got %d", len(reports))
	}
	usage := reports[0]["usage"].(map[string]any)
	if usage["input_tokens"].(float64) != 10 || usage["output_tokens"].(float64) != 5 {
		t.Fatalf("usage report %+v", reports[0])
	}
	if reports[len(reports)-1]["final"] != true {
		t.Fatalf("last report must be final: %+v", reports[len(reports)-1])
	}
}

func TestClientIPTrustsOnlyProxyHops(t *testing.T) {
	trusted := parseCIDRs(defaultTrustedProxies)
	cases := []struct {
		remote, xff, want string
	}{
		{"203.0.113.9:5000", "198.51.100.1", "203.0.113.9"},        // direct client: header ignored
		{"172.18.0.5:5000", "203.0.113.7", "203.0.113.7"},          // via Caddy
		{"172.18.0.5:5000", "1.2.3.4, 203.0.113.7", "203.0.113.7"}, // spoofed left entry ignored
		{"127.0.0.1:5000", "203.0.113.7, 10.0.0.2", "203.0.113.7"}, // two trusted hops
		{"172.18.0.5:5000", "", "172.18.0.5"},                      // no header
		{"172.18.0.5:5000", "garbage, 203.0.113.7", "203.0.113.7"},
	}
	for _, c := range cases {
		r := httptest.NewRequest(http.MethodGet, "/v1/realtime", nil)
		r.RemoteAddr = c.remote
		if c.xff != "" {
			r.Header.Set("X-Forwarded-For", c.xff)
		}
		if got := clientIP(r, trusted); got != c.want {
			t.Errorf("remote=%s xff=%q: got %s want %s", c.remote, c.xff, got, c.want)
		}
	}
}

func TestClientRejectsMaskedServerFrame(t *testing.T) {
	a, b := net.Pipe()
	defer a.Close()
	defer b.Close()
	client := &Conn{conn: a, reader: bufioReader(a), isClient: true, maxMessage: 1 << 10}
	server := &Conn{conn: b, isClient: true} // writes masked frames, like a misbehaving server
	go func() { _ = server.WriteMessage(opText, []byte("hi")) }()
	if _, _, err := client.ReadMessage(); err == nil || !strings.Contains(err.Error(), "must not be masked") {
		t.Fatalf("masked server frame accepted: %v", err)
	}
}

func TestCloseFrameSentOnce(t *testing.T) {
	a, b := net.Pipe()
	defer b.Close()
	peer := &Conn{conn: b, reader: bufioReader(b), isClient: true, maxMessage: 1 << 10}
	local := &Conn{conn: a, reader: bufioReader(a), maxMessage: 1 << 10}
	frames := make(chan int, 4)
	go func() {
		for {
			_, opcode, _, err := peer.readFrame()
			if err != nil {
				close(frames)
				return
			}
			frames <- opcode
		}
	}()
	go func() { _ = peer.writeFrame(opClose, []byte{0x03, 0xE8}) }()
	if _, _, err := local.ReadMessage(); err != io.EOF {
		t.Fatalf("close should end the read: %v", err)
	}
	local.Close(1000, "bye")
	count := 0
	for op := range frames {
		if op == opClose {
			count++
		}
	}
	if count != 1 {
		t.Fatalf("expected one close frame, got %d", count)
	}
}
