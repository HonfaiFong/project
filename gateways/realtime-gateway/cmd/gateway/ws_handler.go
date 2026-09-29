package main

import (
	"encoding/json"
	"fmt"
	"net/http"
	"time"

	"github.com/gorilla/websocket"
	"social-backend/gateways/realtime-gateway/internal/auth"
	"social-backend/gateways/realtime-gateway/internal/connection"
	"social-backend/packages/go/logging"
	"social-backend/packages/go/protocol"
	"social-backend/packages/go/tracing"
)

type wsHandler struct {
	secret   string
	nonces   auth.NonceStore
	hub      *connection.Hub
	logger   *logging.Logger
	upgrader websocket.Upgrader
}

func newWSHandler(secret string, nonces auth.NonceStore, hub *connection.Hub, logger *logging.Logger) http.Handler {
	return &wsHandler{
		secret:   secret,
		nonces:   nonces,
		hub:      hub,
		logger:   logger,
		upgrader: websocket.Upgrader{HandshakeTimeout: 5 * time.Second},
	}
}

func (h *wsHandler) ServeHTTP(w http.ResponseWriter, r *http.Request) {
	if h.hub.IsDraining() {
		http.Error(w, "gateway shutting down", http.StatusServiceUnavailable)
		return
	}
	ticket, ok := h.consumeTicket(w, r)
	if !ok {
		return
	}
	conn, err := h.upgrader.Upgrade(w, r, nil)
	if err != nil {
		h.logger.Error("", "", ticket.UserID, "WebSocket upgrade error", map[string]any{"error": err.Error()})
		return
	}

	connID := tracing.RandomHex(8)
	client := connection.NewClient(h.hub, conn, ticket.UserID, ticket.PersonaID, ticket.DeviceID, connID)

	authOkResp, err := json.Marshal(protocol.Envelope{
		Version:     1,
		RequestID:   tracing.GenerateUUIDv7(),
		TimestampMs: time.Now().UnixMilli(),
		Type:        protocol.EventAuthOK,
	})
	if err != nil {
		h.logger.Error("", "", ticket.UserID, "AUTH_OK encoding failed", map[string]any{"error": err.Error()})
		_ = conn.Close()
		return
	}
	if !h.hub.Register(client) {
		_ = conn.Close()
		return
	}
	h.hub.SendToClient(client, authOkResp)

	h.logger.Info("", "", ticket.UserID, "WebSocket client connected", map[string]any{
		"user_id":    ticket.UserID,
		"persona_id": ticket.PersonaID,
		"device_id":  ticket.DeviceID,
		"conn_id":    connID,
	})
	go client.WritePump()
	go client.ReadPump()
}

func (h *wsHandler) consumeTicket(w http.ResponseWriter, r *http.Request) (*auth.TicketPayload, bool) {
	ticketStr := r.URL.Query().Get("ticket")
	if ticketStr == "" {
		http.Error(w, `{"code":1001005,"message":"missing ticket query parameter"}`, http.StatusUnauthorized)
		return nil, false
	}
	ticket, err := auth.VerifyTicket(ticketStr, h.secret)
	if err != nil {
		h.logger.Warn("", "", "", "WS ticket verification failed", map[string]any{"error": err.Error()})
		http.Error(w, fmt.Sprintf(`{"code":1001007,"message":"ticket verification failed: %s"}`, err.Error()), http.StatusUnauthorized)
		return nil, false
	}
	consumed, err := h.nonces.Consume(r.Context(), ticket.Nonce, time.Unix(ticket.ExpireAt, 0))
	if err != nil {
		h.logger.Error("", "", ticket.UserID, "WS ticket nonce store unavailable", map[string]any{"error": err.Error()})
		http.Error(w, `{"code":1005000,"message":"ticket verification unavailable"}`, http.StatusServiceUnavailable)
		return nil, false
	}
	if !consumed {
		h.logger.Warn("", "", ticket.UserID, "WS ticket replay rejected", map[string]any{"nonce": ticket.Nonce})
		http.Error(w, `{"code":1001007,"message":"ticket already used"}`, http.StatusUnauthorized)
		return nil, false
	}
	return ticket, true
}
