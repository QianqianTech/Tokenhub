package main

import (
	"bufio"
	"io"
	"net"
	"net/http"
	"net/url"
	"strings"
	"testing"
	"time"
)

// DEP-109: upstream WebSocket connections honour HTTP(S)_PROXY via CONNECT.
func TestDialUpstreamThroughConnectProxy(t *testing.T) {
	target, err := net.Listen("tcp", "127.0.0.1:0")
	if err != nil {
		t.Fatal(err)
	}
	defer target.Close()
	go func() {
		conn, err := target.Accept()
		if err != nil {
			return
		}
		defer conn.Close()
		line, _ := bufio.NewReader(conn).ReadString('\n')
		_, _ = io.WriteString(conn, "echo:"+line)
	}()

	proxyListener, err := net.Listen("tcp", "127.0.0.1:0")
	if err != nil {
		t.Fatal(err)
	}
	defer proxyListener.Close()
	seen := make(chan string, 2)
	go func() {
		conn, err := proxyListener.Accept()
		if err != nil {
			return
		}
		defer conn.Close()
		reader := bufio.NewReader(conn)
		req, err := http.ReadRequest(reader)
		if err != nil {
			return
		}
		seen <- req.Method + " " + req.Host
		seen <- req.Header.Get("Proxy-Authorization")
		upstream, err := net.Dial("tcp", req.Host)
		if err != nil {
			_, _ = io.WriteString(conn, "HTTP/1.1 502 Bad Gateway\r\n\r\n")
			return
		}
		defer upstream.Close()
		_, _ = io.WriteString(conn, "HTTP/1.1 200 Connection established\r\n\r\n")
		go func() { _, _ = io.Copy(upstream, reader) }()
		_, _ = io.Copy(conn, upstream)
	}()

	original := proxyForURL
	defer func() { proxyForURL = original }()
	proxyForURL = func(*url.URL) (*url.URL, error) {
		return &url.URL{Scheme: "http", Host: proxyListener.Addr().String(), User: url.UserPassword("u", "p")}, nil
	}

	u, _ := url.Parse("ws://" + target.Addr().String() + "/v1/realtime")
	conn, err := dialUpstream(u, target.Addr().String(), false, 3*time.Second)
	if err != nil {
		t.Fatalf("dial through proxy: %v", err)
	}
	defer conn.Close()
	if got := <-seen; got != "CONNECT "+target.Addr().String() {
		t.Fatalf("proxy saw %q", got)
	}
	if got := <-seen; got != "Basic dTpw" {
		t.Fatalf("proxy authorization %q", got)
	}
	_, _ = io.WriteString(conn, "hello\n")
	_ = conn.SetReadDeadline(time.Now().Add(3 * time.Second))
	reply, _ := bufio.NewReader(conn).ReadString('\n')
	if strings.TrimSpace(reply) != "echo:hello" {
		t.Fatalf("tunnel reply %q", reply)
	}
}

func TestDialUpstreamProxyRefusal(t *testing.T) {
	proxyListener, err := net.Listen("tcp", "127.0.0.1:0")
	if err != nil {
		t.Fatal(err)
	}
	defer proxyListener.Close()
	go func() {
		conn, err := proxyListener.Accept()
		if err != nil {
			return
		}
		defer conn.Close()
		_, _ = http.ReadRequest(bufio.NewReader(conn))
		_, _ = io.WriteString(conn, "HTTP/1.1 403 Forbidden\r\nContent-Length: 0\r\n\r\n")
	}()
	original := proxyForURL
	defer func() { proxyForURL = original }()
	proxyForURL = func(*url.URL) (*url.URL, error) {
		return &url.URL{Scheme: "http", Host: proxyListener.Addr().String()}, nil
	}
	u, _ := url.Parse("wss://api.example.com/v1/realtime")
	if _, err := dialUpstream(u, "api.example.com:443", true, 3*time.Second); err == nil || !strings.Contains(err.Error(), "403") {
		t.Fatalf("expected a CONNECT refusal, got %v", err)
	}
}
