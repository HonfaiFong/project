package connection

import (
	"encoding/json"
	"sync"
	"time"

	"github.com/gorilla/websocket"
	"social-backend/packages/go/protocol"
)

type Client struct {
	Hub       *Hub
	Conn      *websocket.Conn
	Send      chan []byte
	UserID    string
	PersonaID string
	DeviceID  string
	ConnID    string
	mu        sync.Mutex
	isClosed  bool
}

func NewClient(hub *Hub, conn *websocket.Conn, userID, personaID, deviceID, connID string) *Client {
	return &Client{
		Hub:       hub,
		Conn:      conn,
		Send:      make(chan []byte, 256),
		UserID:    userID,
		PersonaID: personaID,
		DeviceID:  deviceID,
		ConnID:    connID,
	}
}

func (c *Client) ReadPump() {
	defer func() {
		c.Hub.Unregister <- c
		_ = c.Conn.Close()
	}()

	_ = c.Conn.SetReadDeadline(time.Now().Add(90 * time.Second))
	c.Conn.SetPongHandler(func(string) error {
		_ = c.Conn.SetReadDeadline(time.Now().Add(90 * time.Second))
		return nil
	})

	for {
		_, message, err := c.Conn.ReadMessage()
		if err != nil {
			break
		}

		var env protocol.Envelope
		if err := json.Unmarshal(message, &env); err != nil {
			continue
		}

		if env.Type == protocol.EventPing {
			resp, _ := json.Marshal(protocol.Envelope{
				Version:     1,
				RequestID:   env.RequestID,
				TimestampMs: time.Now().UnixMilli(),
				Type:        protocol.EventPong,
			})
			c.Send <- resp
		}
	}
}

func (c *Client) WritePump() {
	ticker := time.NewTicker(45 * time.Second)
	defer func() {
		ticker.Stop()
		_ = c.Conn.Close()
	}()

	for {
		select {
		case message, ok := <-c.Send:
			_ = c.Conn.SetWriteDeadline(time.Now().Add(10 * time.Second))
			if !ok {
				_ = c.Conn.WriteMessage(websocket.CloseMessage, []byte{})
				return
			}
			if err := c.Conn.WriteMessage(websocket.TextMessage, message); err != nil {
				return
			}
		case <-ticker.C:
			_ = c.Conn.SetWriteDeadline(time.Now().Add(10 * time.Second))
			if err := c.Conn.WriteMessage(websocket.PingMessage, nil); err != nil {
				return
			}
		}
	}
}
