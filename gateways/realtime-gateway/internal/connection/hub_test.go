package connection

import (
	"context"
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"
	"time"

	"github.com/gorilla/websocket"
)

func TestFullQueueDisconnectsForDurableEvents(t *testing.T) {
	hub := NewHub()
	client := NewClient(hub, nil, "user", "persona", "device", "conn")
	if !hub.Register(client) {
		t.Fatal("registration refused")
	}
	for i := 0; i < cap(client.Send); i++ {
		client.Send <- []byte("queued")
	}
	if hub.PushToUser("user", []byte(`{"type":"TYPING"}`)) {
		t.Fatal("full ephemeral queue should drop")
	}
	if hub.clients["user"]["conn"] != client {
		t.Fatal("ephemeral drop disconnected client")
	}
	if hub.PushToUser("user", []byte(`{"type":"MESSAGE_PUSH"}`)) {
		t.Fatal("full durable queue should not report delivery")
	}
	if len(hub.clients["user"]) != 0 {
		t.Fatal("slow client stayed registered")
	}
	select {
	case <-client.shutdown:
	default:
		t.Fatal("slow client was not told to close")
	}
}

func TestDrainClosesWebSocketWithGoingAway(t *testing.T) {
	hub := NewHub()
	registered := make(chan struct{})
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		conn, err := (&websocket.Upgrader{}).Upgrade(w, r, nil)
		if err != nil {
			return
		}
		client := NewClient(hub, conn, "user", "persona", "device", "conn")
		if !hub.Register(client) {
			_ = conn.Close()
			return
		}
		go client.ReadPump()
		go client.WritePump()
		close(registered)
	}))
	defer server.Close()
	conn, _, err := websocket.DefaultDialer.Dial("ws"+strings.TrimPrefix(server.URL, "http"), nil)
	if err != nil {
		t.Fatal(err)
	}
	defer conn.Close()
	select {
	case <-registered:
	case <-time.After(time.Second):
		t.Fatal("client was not registered")
	}
	ctx, cancel := context.WithTimeout(context.Background(), 2*time.Second)
	defer cancel()
	drained := make(chan error, 1)
	go func() { drained <- hub.Drain(ctx) }()
	_ = conn.SetReadDeadline(time.Now().Add(2 * time.Second))
	_, _, readErr := conn.ReadMessage()
	if !websocket.IsCloseError(readErr, websocket.CloseGoingAway) {
		t.Fatalf("close = %v, want GoingAway", readErr)
	}
	if err := <-drained; err != nil {
		t.Fatalf("drain: %v", err)
	}
	if hub.Register(NewClient(hub, nil, "user", "persona", "device", "late")) {
		t.Fatal("draining hub accepted a client")
	}
}

func TestReadLimitRejectsOversizedMessage(t *testing.T) {
	hub := NewHub()
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		conn, err := (&websocket.Upgrader{}).Upgrade(w, r, nil)
		if err != nil {
			return
		}
		client := NewClient(hub, conn, "user", "persona", "device", "conn")
		if !hub.Register(client) {
			_ = conn.Close()
			return
		}
		go client.ReadPump()
		go client.WritePump()
	}))
	defer server.Close()
	conn, _, err := websocket.DefaultDialer.Dial("ws"+strings.TrimPrefix(server.URL, "http"), nil)
	if err != nil {
		t.Fatal(err)
	}
	defer conn.Close()
	_ = conn.SetWriteDeadline(time.Now().Add(2 * time.Second))
	if err := conn.WriteMessage(websocket.TextMessage, []byte(strings.Repeat("x", maxMessageSize+1))); err != nil {
		t.Fatal(err)
	}
	_ = conn.SetReadDeadline(time.Now().Add(2 * time.Second))
	_, _, err = conn.ReadMessage()
	if !websocket.IsCloseError(err, websocket.CloseMessageTooBig) {
		t.Fatalf("close = %v, want MessageTooBig", err)
	}
	ctx, cancel := context.WithTimeout(context.Background(), 2*time.Second)
	defer cancel()
	if err := hub.Drain(ctx); err != nil {
		t.Fatal(err)
	}
}
