package main

import (
	"context"
	"crypto/hmac"
	"crypto/sha256"
	"encoding/base64"
	"errors"
	"fmt"
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"
	"time"

	"github.com/gorilla/websocket"

	"social-backend/gateways/realtime-gateway/internal/connection"
	"social-backend/packages/go/logging"
)

type failingNonceStore struct{}

type acceptingNonceStore struct{}

type countingNonceStore struct{ calls int }

type blockingNonceStore struct {
	started chan struct{}
	release chan struct{}
}

func (s *blockingNonceStore) Consume(context.Context, string, time.Time) (bool, error) {
	close(s.started)
	<-s.release
	return true, nil
}

func (s *countingNonceStore) Consume(context.Context, string, time.Time) (bool, error) {
	s.calls++
	return true, nil
}

func (acceptingNonceStore) Consume(context.Context, string, time.Time) (bool, error) {
	return true, nil
}

func (failingNonceStore) Consume(context.Context, string, time.Time) (bool, error) {
	return false, errors.New("Redis unavailable")
}

func TestWSHandlerRejectsCrossOrigin(t *testing.T) {
	secret := "test-secret-key-at-least-32-chars-long"
	ticket := signedTestTicket(secret)
	server := httptest.NewServer(newWSHandler(secret, acceptingNonceStore{}, connection.NewHub(), logging.NewLogger("test", "test")))
	defer server.Close()
	header := http.Header{}
	header.Set("Origin", "https://other.example")
	conn, response, err := websocket.DefaultDialer.Dial("ws"+strings.TrimPrefix(server.URL, "http")+"?ticket="+ticket, header)
	if conn != nil {
		_ = conn.Close()
	}
	if err == nil || response == nil || response.StatusCode != http.StatusForbidden {
		t.Fatalf("cross-origin handshake: response=%v err=%v, want 403", response, err)
	}
}

func TestWSHandlerFailsClosedWhenNonceStoreUnavailable(t *testing.T) {
	secret := "test-secret-key-at-least-32-chars-long"
	ticket := signedTestTicket(secret)

	handler := newWSHandler(secret, failingNonceStore{}, connection.NewHub(), logging.NewLogger("test", "test"))
	req := httptest.NewRequest(http.MethodGet, "/ws?ticket="+ticket, nil)
	resp := httptest.NewRecorder()
	handler.ServeHTTP(resp, req)
	if resp.Code != http.StatusServiceUnavailable {
		t.Fatalf("expected 503 when nonce storage fails, got %d", resp.Code)
	}
}

func TestWSHandlerDoesNotConsumeTicketAfterDrainStarts(t *testing.T) {
	hub := connection.NewHub()
	hub.StartDraining()
	nonces := &countingNonceStore{}
	handler := newWSHandler("test-secret-key-at-least-32-chars-long", nonces, hub, logging.NewLogger("test", "test"))
	response := httptest.NewRecorder()
	handler.ServeHTTP(response, httptest.NewRequest(http.MethodGet, "/ws?ticket=unused", nil))
	if response.Code != http.StatusServiceUnavailable || nonces.calls != 0 {
		t.Fatalf("response=%d nonce calls=%d, want 503 without consuming ticket", response.Code, nonces.calls)
	}
}

func TestWSHandlerDoesNotUpgradeWhenDrainStartsDuringNonceConsume(t *testing.T) {
	secret := "test-secret-key-at-least-32-chars-long"
	hub := connection.NewHub()
	nonces := &blockingNonceStore{started: make(chan struct{}), release: make(chan struct{})}
	handler := newWSHandler(secret, nonces, hub, logging.NewLogger("test", "test"))
	response := httptest.NewRecorder()
	done := make(chan struct{})
	go func() {
		handler.ServeHTTP(response, httptest.NewRequest(http.MethodGet, "/ws?ticket="+signedTestTicket(secret), nil))
		close(done)
	}()
	select {
	case <-nonces.started:
	case <-time.After(time.Second):
		t.Fatal("nonce consumption did not start")
	}
	hub.StartDraining()
	close(nonces.release)
	select {
	case <-done:
	case <-time.After(time.Second):
		t.Fatal("handler did not return")
	}
	if response.Code != http.StatusServiceUnavailable {
		t.Fatalf("response=%d, want 503 before upgrade", response.Code)
	}
}

func signedTestTicket(secret string) string {
	expiresAt := time.Now().Add(time.Minute).Unix()
	data := fmt.Sprintf("user:persona:device:nonce:%d", expiresAt)
	mac := hmac.New(sha256.New, []byte(secret))
	_, _ = mac.Write([]byte(data))
	return base64.RawURLEncoding.EncodeToString([]byte(data + ":" + fmt.Sprintf("%x", mac.Sum(nil))))
}
