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
	"testing"
	"time"

	"social-backend/gateways/realtime-gateway/internal/connection"
	"social-backend/packages/go/logging"
)

type failingNonceStore struct{}

func (failingNonceStore) Consume(context.Context, string, time.Time) (bool, error) {
	return false, errors.New("Redis unavailable")
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
