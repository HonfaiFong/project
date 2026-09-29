package connection

import (
	"context"
	"encoding/json"
	"sync"

	"social-backend/packages/go/protocol"
)

type Hub struct {
	clients  map[string]map[string]*Client // userID -> connID -> Client
	mu       sync.Mutex
	draining bool
	pumps    sync.WaitGroup
}

func NewHub() *Hub { return &Hub{clients: make(map[string]map[string]*Client)} }

func (h *Hub) Register(client *Client) bool {
	h.mu.Lock()
	defer h.mu.Unlock()
	if h.draining {
		return false
	}
	if h.clients[client.UserID] == nil {
		h.clients[client.UserID] = make(map[string]*Client)
	}
	h.clients[client.UserID][client.ConnID] = client
	h.pumps.Add(2)
	return true
}

func (h *Hub) Unregister(client *Client) {
	h.mu.Lock()
	defer h.mu.Unlock()
	if userClients := h.clients[client.UserID]; userClients != nil && userClients[client.ConnID] == client {
		delete(userClients, client.ConnID)
		close(client.Send)
		if len(userClients) == 0 {
			delete(h.clients, client.UserID)
		}
	}
}

func (h *Hub) PumpDone() { h.pumps.Done() }

func (h *Hub) IsDraining() bool {
	h.mu.Lock()
	defer h.mu.Unlock()
	return h.draining
}

// Drain rejects new clients and asks every existing writer to send GoingAway.
func (h *Hub) Drain(ctx context.Context) error {
	h.mu.Lock()
	h.draining = true
	clients := make([]*Client, 0)
	for _, userClients := range h.clients {
		for _, client := range userClients {
			clients = append(clients, client)
			client.stop("server closing")
		}
	}
	h.mu.Unlock()

	done := make(chan struct{})
	go func() { h.pumps.Wait(); close(done) }()
	select {
	case <-done:
		return nil
	case <-ctx.Done():
		for _, client := range clients {
			_ = client.Conn.Close()
		}
		<-done
		return ctx.Err()
	}
}

// removeSlow runs under h.mu. It removes the client before closing Send, so
// another producer cannot enqueue into a closed channel.
func (h *Hub) removeSlow(client *Client) {
	userClients := h.clients[client.UserID]
	if userClients == nil || userClients[client.ConnID] != client {
		return
	}
	delete(userClients, client.ConnID)
	close(client.Send)
	client.stop("slow consumer; reconnect to resynchronize")
	if len(userClients) == 0 {
		delete(h.clients, client.UserID)
	}
}

// enqueue runs under h.mu. Only TYPING may be dropped on a full queue.
func (h *Hub) enqueue(client *Client, message []byte, ephemeral bool) bool {
	select {
	case client.Send <- message:
		return true
	default:
		if !ephemeral {
			h.removeSlow(client)
		}
		return false
	}
}

func (h *Hub) SendToClient(client *Client, message []byte) bool {
	h.mu.Lock()
	defer h.mu.Unlock()
	if h.draining || h.clients[client.UserID][client.ConnID] != client {
		return false
	}
	return h.enqueue(client, message, false)
}

func (h *Hub) PushToUser(userID string, message []byte) bool {
	h.mu.Lock()
	defer h.mu.Unlock()
	if h.draining {
		return false
	}
	pushed := false
	for _, client := range h.clients[userID] {
		if h.enqueue(client, message, isEphemeral(message)) {
			pushed = true
		}
	}
	return pushed
}

func (h *Hub) Broadcast(message []byte) {
	h.mu.Lock()
	defer h.mu.Unlock()
	if h.draining {
		return
	}
	ephemeral := isEphemeral(message)
	for _, userClients := range h.clients {
		for _, client := range userClients {
			h.enqueue(client, message, ephemeral)
		}
	}
}

func isEphemeral(message []byte) bool {
	var envelope struct {
		Type string `json:"type"`
	}
	return json.Unmarshal(message, &envelope) == nil && envelope.Type == protocol.EventTyping
}
