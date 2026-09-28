package main

import (
	"encoding/json"
	"fmt"
	"log"
	"net/http"
	"os"
	"os/signal"
	"syscall"
	"time"

	"github.com/gorilla/websocket"
	"social-backend/gateways/realtime-gateway/internal/auth"
	"social-backend/gateways/realtime-gateway/internal/connection"
	"social-backend/packages/go/logging"
	"social-backend/packages/go/protocol"
	"social-backend/packages/go/tracing"
)

var upgrader = websocket.Upgrader{
	CheckOrigin: func(r *http.Request) bool {
		return true // Configurable CORS
	},
}

func main() {
	port := os.Getenv("GATEWAY_PORT")
	if port == "" {
		port = "8080"
	}
	secret := os.Getenv("WS_TICKET_SECRET")
	if secret == "" {
		secret = "ws-ticket-secret-32-chars-key-abcdef"
	}

	logger := logging.NewLogger("realtime-gateway", "gw-01")
	hub := connection.NewHub()
	go hub.Run()

	http.HandleFunc("/health", func(w http.ResponseWriter, r *http.Request) {
		w.Header().Set("Content-Type", "application/json")
		_ = json.NewEncoder(w).Encode(map[string]any{
			"status":  "SERVING",
			"service": "realtime-gateway",
		})
	})

	http.HandleFunc("/ws", func(w http.ResponseWriter, r *http.Request) {
		ticketStr := r.URL.Query().Get("ticket")
		if ticketStr == "" {
			http.Error(w, `{"code":1001005,"message":"missing ticket query parameter"}`, http.StatusUnauthorized)
			return
		}

		ticket, err := auth.VerifyTicket(ticketStr, secret)
		if err != nil {
			logger.Warn("", "", "", "WS ticket verification failed", map[string]any{"error": err.Error()})
			http.Error(w, fmt.Sprintf(`{"code":1001007,"message":"ticket verification failed: %s"}`, err.Error()), http.StatusUnauthorized)
			return
		}

		conn, err := upgrader.Upgrade(w, r, nil)
		if err != nil {
			logger.Error("", "", ticket.UserID, "WebSocket upgrade error", map[string]any{"error": err.Error()})
			return
		}

		connID := tracing.RandomHex(8)
		client := connection.NewClient(hub, conn, ticket.UserID, ticket.PersonaID, ticket.DeviceID, connID)
		hub.Register <- client

		// Send AUTH_OK confirmation envelope
		authOkResp, _ := json.Marshal(protocol.Envelope{
			Version:     1,
			RequestID:   tracing.GenerateUUIDv7(),
			TimestampMs: time.Now().UnixMilli(),
			Type:        protocol.EventAuthOK,
		})
		client.Send <- authOkResp

		logger.Info("", "", ticket.UserID, "WebSocket client connected", map[string]any{
			"user_id":    ticket.UserID,
			"persona_id": ticket.PersonaID,
			"device_id":  ticket.DeviceID,
			"conn_id":    connID,
		})

		go client.WritePump()
		go client.ReadPump()
	})

	server := &http.Server{
		Addr: ":" + port,
	}

	go func() {
		logger.Info("", "", "", fmt.Sprintf("Realtime Gateway listening on :%s", port), nil)
		if err := server.ListenAndServe(); err != nil && err != http.ErrServerClosed {
			log.Fatalf("Gateway listen error: %v", err)
		}
	}()

	quit := make(chan os.Signal, 1)
	signal.Notify(quit, syscall.SIGINT, syscall.SIGTERM)
	<-quit

	logger.Info("", "", "", "Shutting down Realtime Gateway...", nil)
}
