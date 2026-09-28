package protocol

// Envelope matches the WebSocket protocol envelope
type Envelope struct {
	Version     uint32 `json:"version"`
	RequestID   string `json:"request_id"`
	Sequence    uint64 `json:"sequence"`
	TimestampMs int64  `json:"timestamp_ms"`
	Type        string `json:"type"`
	Payload     []byte `json:"payload"`
}

// WebSocket Event Types per Section 17
const (
	EventAuth   = "AUTH"
	EventAuthOK = "AUTH_OK"

	EventPing = "PING"
	EventPong = "PONG"

	EventMessageSend = "MESSAGE_SEND"
	EventMessageAck  = "MESSAGE_ACK"
	EventMessagePush = "MESSAGE_PUSH"

	EventMessageDelivered = "MESSAGE_DELIVERED"
	EventMessageRead      = "MESSAGE_READ"

	EventTyping = "TYPING"
	EventError  = "ERROR"
)

// WSTicket represents the validated ticket payload
type WSTicket struct {
	UserID    string `json:"user_id"`
	PersonaID string `json:"persona_id"`
	DeviceID  string `json:"device_id"`
	Nonce     string `json:"nonce"`
	ExpireAt  int64  `json:"expire_at"`
}
