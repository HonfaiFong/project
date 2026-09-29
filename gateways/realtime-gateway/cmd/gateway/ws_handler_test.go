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

func (acceptingNonceStore) Consume(context.Context, string, time.Time) (bool, error) {
	return true, nil
}

func (failingNonceStore) Consume(context.Context, string, time.Time) (bool, error) {
	return false, errors.New("Redis unavailable")
}

func TestWSHandlerRejectsCrossOrigin(t *testing.T) {
	secret := "test-secret-key-at-least-32-chars-long"
	expiresAt := time.Now().Add(time.Minute).Unix()
	data := fmt.Sprintf("user:persona:device:nonce:%d", expiresAt)
	mac := hmac.New(sha256.New, []byte(secret))
	_, _ = mac.Write([]byte(data))
	ticket := base64.RawURLEncoding.EncodeToString([]byte(data + ":" + fmt.Sprintf("%x", mac.Sum(nil))))
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
	expiresAt := time.Now().Add(time.Minute).Unix()
	data := fmt.Sprintf("user:persona:device:nonce:%d", expiresAt)
	mac := hmac.New(sha256.New, []byte(secret))
	_, _ = mac.Write([]byte(data))
	ticket := base64.RawURLEncoding.EncodeToString([]byte(data + ":" + fmt.Sprintf("%x", mac.Sum(nil))))

	handler := newWSHandler(secret, failingNonceStore{}, connection.NewHub(), logging.NewLogger("test", "test"))
	req := httptest.NewRequest(http.MethodGet, "/ws?ticket="+ticket, nil)
	resp := httptest.NewRecorder()
	handler.ServeHTTP(resp, req)
	if resp.Code != http.StatusServiceUnavailable {
		t.Fatalf("expected 503 when nonce storage fails, got %d", resp.Code)
	}
}
