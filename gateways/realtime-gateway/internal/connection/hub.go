package connection

import "sync"

type Hub struct {
	Clients    map[string]map[string]*Client // userID -> connID -> Client
	Broadcast  chan []byte
	Register   chan *Client
	Unregister chan *Client
	mu         sync.RWMutex
}

func NewHub() *Hub {
	return &Hub{
		Clients:    make(map[string]map[string]*Client),
		Broadcast:  make(chan []byte),
		Register:   make(chan *Client),
		Unregister: make(chan *Client),
	}
}

func (h *Hub) Run() {
	for {
		select {
		case client := <-h.Register:
			h.mu.Lock()
			if _, ok := h.Clients[client.UserID]; !ok {
				h.Clients[client.UserID] = make(map[string]*Client)
			}
			h.Clients[client.UserID][client.ConnID] = client
			h.mu.Unlock()

		case client := <-h.Unregister:
			h.mu.Lock()
			if userClients, ok := h.Clients[client.UserID]; ok {
				if _, exists := userClients[client.ConnID]; exists {
					delete(userClients, client.ConnID)
					close(client.Send)
					if len(userClients) == 0 {
						delete(h.Clients, client.UserID)
					}
				}
			}
			h.mu.Unlock()

		case message := <-h.Broadcast:
			h.mu.RLock()
			for _, userClients := range h.Clients {
				for _, client := range userClients {
					select {
					case client.Send <- message:
					default:
					}
				}
			}
			h.mu.RUnlock()
		}
	}
}

func (h *Hub) PushToUser(userID string, message []byte) bool {
	h.mu.RLock()
	defer h.mu.RUnlock()

	userClients, ok := h.Clients[userID]
	if !ok || len(userClients) == 0 {
		return false
	}

	pushed := false
	for _, client := range userClients {
		select {
		case client.Send <- message:
			pushed = true
		default:
		}
	}
	return pushed
}
