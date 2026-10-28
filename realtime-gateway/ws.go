package main

// Minimal RFC 6455 WebSocket implementation (standard library only, so the
// relay builds offline): server upgrade, client dial (ws/wss), message
// framing with fragmentation, masking, ping/pong and close.

import (
	"bufio"
	"crypto/rand"
	"crypto/sha1"
	"crypto/tls"
	"encoding/base64"
	"encoding/binary"
	"errors"
	"fmt"
	"io"
	"net"
	"net/http"
	"net/url"
	"strings"
	"sync"
	"sync/atomic"
	"time"
)

const (
	opContinuation = 0x0
	opText         = 0x1
	opBinary       = 0x2
	opClose        = 0x8
	opPing         = 0x9
	opPong         = 0xA

	websocketGUID = "258EAFA5-E914-47DA-95CA-C5AB0DC85B11"
)

var errMessageTooLarge = errors.New("websocket: message too large")

// Conn is one side of a WebSocket connection.
type Conn struct {
	conn       net.Conn
	reader     *bufio.Reader
	isClient   bool // clients mask outgoing frames
	maxMessage int64
	writeMu    sync.Mutex
	closeOnce  sync.Once
	closeSent  atomic.Bool // a close frame went out (echo or our own)
}

// AcceptKey computes Sec-WebSocket-Accept for a Sec-WebSocket-Key.
func AcceptKey(key string) string {
	sum := sha1.Sum([]byte(key + websocketGUID))
	return base64.StdEncoding.EncodeToString(sum[:])
}

// Upgrade turns an HTTP request into a server-side WebSocket connection.
// subprotocol, when non-empty, is echoed in Sec-WebSocket-Protocol.
func Upgrade(w http.ResponseWriter, r *http.Request, subprotocol string, maxMessage int64) (*Conn, error) {
	if status, message := CheckUpgrade(r); status != 0 {
		if status == http.StatusUpgradeRequired {
			w.Header().Set("Sec-WebSocket-Version", "13")
		}
		http.Error(w, message, status)
		return nil, errors.New("websocket: " + message)
	}
	key := r.Header.Get("Sec-WebSocket-Key")
	hijacker, ok := w.(http.Hijacker)
	if !ok {
		http.Error(w, "hijacking not supported", http.StatusInternalServerError)
		return nil, errors.New("websocket: hijacking not supported")
	}
	conn, rw, err := hijacker.Hijack()
	if err != nil {
		return nil, err
	}
	response := "HTTP/1.1 101 Switching Protocols\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Accept: " + AcceptKey(key) + "\r\n"
	if subprotocol != "" {
		response += "Sec-WebSocket-Protocol: " + subprotocol + "\r\n"
	}
	response += "\r\n"
	if _, err := rw.WriteString(response); err != nil {
		conn.Close()
		return nil, err
	}
	if err := rw.Flush(); err != nil {
		conn.Close()
		return nil, err
	}
	return &Conn{conn: conn, reader: rw.Reader, maxMessage: maxMessage}, nil
}

// CheckUpgrade validates the handshake headers without touching the
// connection; it returns 0 when the request can be upgraded, otherwise the
// HTTP status and message to reject it with. Callers run it before doing any
// work (authorization, upstream dial) so a malformed handshake costs nothing.
func CheckUpgrade(r *http.Request) (int, string) {
	if !headerContains(r.Header, "Connection", "upgrade") || !strings.EqualFold(r.Header.Get("Upgrade"), "websocket") {
		return http.StatusBadRequest, "websocket upgrade required"
	}
	if r.Header.Get("Sec-WebSocket-Version") != "13" {
		return http.StatusUpgradeRequired, "unsupported websocket version"
	}
	if r.Header.Get("Sec-WebSocket-Key") == "" {
		return http.StatusBadRequest, "missing Sec-WebSocket-Key"
	}
	return 0, ""
}

// Dial opens a client WebSocket connection to ws:// or wss:// rawURL.
func Dial(rawURL string, headers http.Header, timeout time.Duration, maxMessage int64) (*Conn, *http.Response, error) {
	u, err := url.Parse(rawURL)
	if err != nil {
		return nil, nil, err
	}
	host := u.Host
	secure := u.Scheme == "wss" || u.Scheme == "https"
	if u.Port() == "" {
		if secure {
			host += ":443"
		} else {
			host += ":80"
		}
	}
	dialer := &net.Dialer{Timeout: timeout}
	var conn net.Conn
	if secure {
		conn, err = tls.DialWithDialer(dialer, "tcp", host, &tls.Config{ServerName: u.Hostname(), MinVersion: tls.VersionTLS12})
	} else {
		conn, err = dialer.Dial("tcp", host)
	}
	if err != nil {
		return nil, nil, err
	}
	keyBytes := make([]byte, 16)
	if _, err := rand.Read(keyBytes); err != nil {
		conn.Close()
		return nil, nil, err
	}
	key := base64.StdEncoding.EncodeToString(keyBytes)
	path := u.RequestURI()
	var b strings.Builder
	fmt.Fprintf(&b, "GET %s HTTP/1.1\r\nHost: %s\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Key: %s\r\nSec-WebSocket-Version: 13\r\n", path, u.Host, key)
	for name, values := range headers {
		for _, value := range values {
			fmt.Fprintf(&b, "%s: %s\r\n", name, value)
		}
	}
	b.WriteString("\r\n")
	_ = conn.SetDeadline(time.Now().Add(timeout))
	if _, err := io.WriteString(conn, b.String()); err != nil {
		conn.Close()
		return nil, nil, err
	}
	reader := bufio.NewReader(conn)
	resp, err := http.ReadResponse(reader, &http.Request{Method: http.MethodGet})
	if err != nil {
		conn.Close()
		return nil, nil, err
	}
	if resp.StatusCode != http.StatusSwitchingProtocols {
		body, _ := io.ReadAll(io.LimitReader(resp.Body, 4096))
		resp.Body.Close()
		conn.Close()
		return nil, resp, fmt.Errorf("websocket: upstream handshake status %d: %s", resp.StatusCode, strings.TrimSpace(string(body)))
	}
	if resp.Header.Get("Sec-WebSocket-Accept") != AcceptKey(key) {
		conn.Close()
		return nil, resp, errors.New("websocket: bad Sec-WebSocket-Accept")
	}
	_ = conn.SetDeadline(time.Time{})
	return &Conn{conn: conn, reader: reader, isClient: true, maxMessage: maxMessage}, resp, nil
}

// ReadMessage returns the next data message (text or binary), answering
// pings and reassembling fragments. A close frame returns io.EOF after the
// close is echoed.
func (c *Conn) ReadMessage() (int, []byte, error) {
	var (
		messageType int
		message     []byte
	)
	for {
		fin, opcode, payload, err := c.readFrame()
		if err != nil {
			return 0, nil, err
		}
		switch opcode {
		case opPing:
			if err := c.writeFrame(opPong, payload); err != nil {
				return 0, nil, err
			}
			continue
		case opPong:
			continue
		case opClose:
			// Echo the peer's close once; Close() later skips its own frame.
			if c.closeSent.CompareAndSwap(false, true) {
				echo := payload
				if len(echo) > 2 {
					echo = echo[:2]
				}
				_ = c.writeFrame(opClose, echo)
			}
			return 0, nil, io.EOF
		case opText, opBinary:
			if message != nil {
				return 0, nil, errors.New("websocket: unexpected data frame inside a fragmented message")
			}
			messageType = opcode
			message = append([]byte{}, payload...)
		case opContinuation:
			if message == nil {
				return 0, nil, errors.New("websocket: continuation without a message")
			}
			message = append(message, payload...)
		default:
			return 0, nil, fmt.Errorf("websocket: unknown opcode %d", opcode)
		}
		if c.maxMessage > 0 && int64(len(message)) > c.maxMessage {
			return 0, nil, errMessageTooLarge
		}
		if fin {
			return messageType, message, nil
		}
	}
}

// WriteMessage sends one data message as a single frame.
func (c *Conn) WriteMessage(messageType int, payload []byte) error {
	return c.writeFrame(messageType, payload)
}

// Close sends a close frame (with status code) and closes the socket.
func (c *Conn) Close(code int, reason string) {
	c.closeOnce.Do(func() {
		payload := make([]byte, 2+len(reason))
		binary.BigEndian.PutUint16(payload, uint16(code))
		copy(payload[2:], reason)
		if c.closeSent.CompareAndSwap(false, true) {
			_ = c.conn.SetWriteDeadline(time.Now().Add(2 * time.Second))
			_ = c.writeFrame(opClose, payload)
		}
		_ = c.conn.Close()
	})
}

func (c *Conn) readFrame() (bool, int, []byte, error) {
	var header [2]byte
	if _, err := io.ReadFull(c.reader, header[:]); err != nil {
		return false, 0, nil, err
	}
	fin := header[0]&0x80 != 0
	opcode := int(header[0] & 0x0F)
	masked := header[1]&0x80 != 0
	length := int64(header[1] & 0x7F)
	switch length {
	case 126:
		var ext [2]byte
		if _, err := io.ReadFull(c.reader, ext[:]); err != nil {
			return false, 0, nil, err
		}
		length = int64(binary.BigEndian.Uint16(ext[:]))
	case 127:
		var ext [8]byte
		if _, err := io.ReadFull(c.reader, ext[:]); err != nil {
			return false, 0, nil, err
		}
		length = int64(binary.BigEndian.Uint64(ext[:]))
	}
	if length < 0 || (c.maxMessage > 0 && length > c.maxMessage) {
		return false, 0, nil, errMessageTooLarge
	}
	if !c.isClient && !masked {
		return false, 0, nil, errors.New("websocket: client frames must be masked")
	}
	if c.isClient && masked {
		return false, 0, nil, errors.New("websocket: server frames must not be masked")
	}
	var mask [4]byte
	if masked {
		if _, err := io.ReadFull(c.reader, mask[:]); err != nil {
			return false, 0, nil, err
		}
	}
	payload := make([]byte, length)
	if _, err := io.ReadFull(c.reader, payload); err != nil {
		return false, 0, nil, err
	}
	if masked {
		for i := range payload {
			payload[i] ^= mask[i%4]
		}
	}
	return fin, opcode, payload, nil
}

func (c *Conn) writeFrame(opcode int, payload []byte) error {
	c.writeMu.Lock()
	defer c.writeMu.Unlock()
	frame := make([]byte, 0, len(payload)+14)
	frame = append(frame, 0x80|byte(opcode))
	maskBit := byte(0)
	if c.isClient {
		maskBit = 0x80
	}
	switch n := len(payload); {
	case n < 126:
		frame = append(frame, maskBit|byte(n))
	case n <= 0xFFFF:
		frame = append(frame, maskBit|126, byte(n>>8), byte(n))
	default:
		frame = append(frame, maskBit|127)
		var ext [8]byte
		binary.BigEndian.PutUint64(ext[:], uint64(n))
		frame = append(frame, ext[:]...)
	}
	if c.isClient {
		var mask [4]byte
		if _, err := rand.Read(mask[:]); err != nil {
			return err
		}
		frame = append(frame, mask[:]...)
		start := len(frame)
		frame = append(frame, payload...)
		for i := range payload {
			frame[start+i] ^= mask[i%4]
		}
	} else {
		frame = append(frame, payload...)
	}
	_, err := c.conn.Write(frame)
	return err
}

func headerContains(h http.Header, name, token string) bool {
	for _, value := range h.Values(name) {
		for _, part := range strings.Split(value, ",") {
			if strings.EqualFold(strings.TrimSpace(part), token) {
				return true
			}
		}
	}
	return false
}
