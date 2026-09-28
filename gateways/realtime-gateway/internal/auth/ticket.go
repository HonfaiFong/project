package auth

import (
	"crypto/hmac"
	"crypto/sha256"
	"encoding/base64"
	"errors"
	"fmt"
	"strconv"
	"strings"
	"time"
)

type TicketPayload struct {
	UserID    string
	PersonaID string
	DeviceID  string
	Nonce     string
	ExpireAt  int64
}

func VerifyTicket(ticketStr, secret string) (*TicketPayload, error) {
	decodedBytes, err := base64.StdEncoding.DecodeString(ticketStr)
	if err != nil {
		return nil, fmt.Errorf("invalid base64 ticket: %w", err)
	}

	decoded := string(decodedBytes)
	parts := strings.Split(decoded, ":")
	if len(parts) != 6 {
		return nil, errors.New("malformed ticket structure")
	}

	userID := parts[0]
	personaID := parts[1]
	deviceID := parts[2]
	nonce := parts[3]
	expireAtStr := parts[4]
	signature := parts[5]

	expireAt, err := strconv.ParseInt(expireAtStr, 10, 64)
	if err != nil {
		return nil, errors.New("invalid expire_at in ticket")
	}

	if expireAt < time.Now().Unix() {
		return nil, errors.New("ticket has expired")
	}

	signData := fmt.Sprintf("%s:%s:%s:%s:%d", userID, personaID, deviceID, nonce, expireAt)
	mac := hmac.New(sha256.New, []byte(secret))
	mac.Write([]byte(signData))
	expectedSignature := fmt.Sprintf("%x", mac.Sum(nil))

	if !hmac.Equal([]byte(signature), []byte(expectedSignature)) {
		return nil, errors.New("invalid ticket signature")
	}

	return &TicketPayload{
		UserID:    userID,
		PersonaID: personaID,
		DeviceID:  deviceID,
		Nonce:     nonce,
		ExpireAt:  expireAt,
	}, nil
}
