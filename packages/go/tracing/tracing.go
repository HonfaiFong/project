package tracing

import (
	"crypto/rand"
	"encoding/hex"
	"fmt"
	"time"
)

type TraceContext struct {
	RequestID   string
	TraceID     string
	UserID      string
	PersonaID   string
	DeviceID    string
	AppVersion  string
	Traceparent string
}

func NewTraceContext(requestID, traceID, userID, personaID, deviceID, appVersion string) *TraceContext {
	if requestID == "" {
		requestID = GenerateUUIDv7()
	}
	if traceID == "" {
		traceID = RandomHex(16)
	}
	traceparent := fmt.Sprintf("00-%s-%s-01", traceID, RandomHex(8))

	return &TraceContext{
		RequestID:   requestID,
		TraceID:     traceID,
		UserID:      userID,
		PersonaID:   personaID,
		DeviceID:    deviceID,
		AppVersion:  appVersion,
		Traceparent: traceparent,
	}
}

func RandomHex(n int) string {
	bytes := make([]byte, n)
	_, _ = rand.Read(bytes)
	return hex.EncodeToString(bytes)
}

func GenerateUUIDv7() string {
	now := time.Now().UnixMilli()
	var uuid [16]byte
	uuid[0] = byte(now >> 40)
	uuid[1] = byte(now >> 32)
	uuid[2] = byte(now >> 24)
	uuid[3] = byte(now >> 16)
	uuid[4] = byte(now >> 8)
	uuid[5] = byte(now)

	_, _ = rand.Read(uuid[6:])
	uuid[6] = (uuid[6] & 0x0f) | 0x70 // version 7
	uuid[8] = (uuid[8] & 0x3f) | 0x80 // variant 10

	return fmt.Sprintf("%08x-%04x-%04x-%04x-%012x",
		uuid[0:4],
		uuid[4:6],
		uuid[6:8],
		uuid[8:10],
		uuid[10:16])
}
