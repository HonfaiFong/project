package auth

import (
	"crypto/hmac"
	"crypto/sha256"
	"encoding/base64"
	"fmt"
	"testing"
	"time"
)

func TestVerifyTicketValid(t *testing.T) {
	secret := "test-secret-key-at-least-32-chars-long"
	now := time.Now().Unix() + 60

	ticketStr, err := generateTestTicket("20001", "30001", "dev_01", "nonce123", now, secret)
	if err != nil {
		t.Fatalf("Failed to generate test ticket: %v", err)
	}

	payload, err := VerifyTicket(ticketStr, secret)
	if err != nil {
		t.Fatalf("Expected valid ticket, got err: %v", err)
	}

	if payload.UserID != "20001" {
		t.Errorf("Expected UserID 20001, got %s", payload.UserID)
	}
	if payload.PersonaID != "30001" {
		t.Errorf("Expected PersonaID 30001, got %s", payload.PersonaID)
	}
}

func TestVerifyTicketExpired(t *testing.T) {
	secret := "test-secret-key-at-least-32-chars-long"
	expired := time.Now().Unix() - 10

	ticketStr, err := generateTestTicket("20001", "30001", "dev_01", "nonce123", expired, secret)
	if err != nil {
		t.Fatalf("Failed to generate test ticket: %v", err)
	}

	_, err = VerifyTicket(ticketStr, secret)
	if err == nil {
		t.Fatal("Expected error for expired ticket, got nil")
	}
}

func TestVerifyTicketSignatureMismatch(t *testing.T) {
	secret := "test-secret-key-at-least-32-chars-long"
	other := "other-secret-key-at-least-32-chars"
	now := time.Now().Unix() + 60

	ticketStr, err := generateTestTicket("20001", "30001", "dev_01", "nonce123", now, secret)
	if err != nil {
		t.Fatalf("Failed to generate test ticket: %v", err)
	}

	if _, err := VerifyTicket(ticketStr, other); err == nil {
		t.Fatal("Expected error for signature mismatch, got nil")
	}
}

func TestVerifyTicketAcceptsURLSafeBase64(t *testing.T) {
	// 签名包含不适合 URL 的标准 base64 字符时，也须在往返传输后保持完整，因为票据现在使用 base64url 编码。
	secret := "test-secret-key-at-least-32-chars-long"
	now := time.Now().Unix() + 60

	ticketStr, err := generateTestTicket("20001", "30001", "dev_01", "deadbeefdeadbeef", now, secret)
	if err != nil {
		t.Fatalf("Failed to generate test ticket: %v", err)
	}

	for _, c := range ticketStr {
		if c == '+' || c == '/' || c == '=' {
			t.Fatalf("Ticket contains URL-unsafe base64 character %q", string(c))
		}
	}

	if _, err := VerifyTicket(ticketStr, secret); err != nil {
		t.Fatalf("Expected valid URL-safe ticket, got err: %v", err)
	}
}

func generateTestTicket(userID, personaID, deviceID, nonce string, expireAt int64, secret string) (string, error) {
	signData := fmt.Sprintf("%s:%s:%s:%s:%d", userID, personaID, deviceID, nonce, expireAt)
	mac := hmac.New(sha256.New, []byte(secret))
	mac.Write([]byte(signData))
	sig := fmt.Sprintf("%x", mac.Sum(nil))

	full := fmt.Sprintf("%s:%s", signData, sig)
	return base64.RawURLEncoding.EncodeToString([]byte(full)), nil
}
