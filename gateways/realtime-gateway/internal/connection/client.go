package connection

import (
	"encoding/json"
	"sync"
	"time"

	"github.com/gorilla/websocket"
	"social-backend/packages/go/protocol"
)

const maxMessageSize = 64 * 1024

type Client struct {
	Hub         *Hub
	Conn        *websocket.Conn
	Send        chan []byte
	UserID      string
	PersonaID   string
	DeviceID    string
	ConnID      string
	shutdown    chan struct{}
	stopOnce    sync.Once
	closeReason string
}

func NewClient(hub *Hub, conn *websocket.Conn, userID, personaID, deviceID, connID string) *Client {
	return &Client{Hub: hub, Conn: conn, Send: make(chan []byte, 256), UserID: userID,
		PersonaID: personaID, DeviceID: deviceID, ConnID: connID, shutdown: make(chan struct{})}
}

func (c *Client) stop(reason string) {
	c.stopOnce.Do(func() {
		c.closeReason = reason
		close(c.shutdown)
	})
}

func (c *Client) ReadPump() {
	defer c.Hub.PumpDone()
	defer func() { c.Hub.Unregister(c); _ = c.Conn.Close() }()

	c.Conn.SetReadLimit(maxMessageSize)
	_ = c.Conn.SetReadDeadline(time.Now().Add(90 * time.Second))
	c.Conn.SetPongHandler(func(string) error {
		return c.Conn.SetReadDeadline(time.Now().Add(90 * time.Second))
	})
	for {
		_, message, err := c.Conn.ReadMessage()
		if err != nil {
			return
		}
		var env protocol.Envelope
		if err := json.Unmarshal(message, &env); err != nil {
			continue
		}
		if env.Type == protocol.EventPing {
			resp, _ := json.Marshal(protocol.Envelope{Version: 1, RequestID: env.RequestID,
				TimestampMs: time.Now().UnixMilli(), Type: protocol.EventPong})
			if !c.Hub.SendToClient(c, resp) {
				return
			}
		}
	}
}

func (c *Client) WritePump() {
	defer c.Hub.PumpDone()
	ticker := time.NewTicker(45 * time.Second)
	defer func() { ticker.Stop(); _ = c.Conn.Close() }()
	for {
		// Give shutdown priority over buffered application messages.
		select {
		case <-c.shutdown:
			_ = c.Conn.SetWriteDeadline(time.Now().Add(10 * time.Second))
			_ = c.Conn.WriteMessage(websocket.CloseMessage,
				websocket.FormatCloseMessage(websocket.CloseGoingAway, c.closeReason))
			return
		default:
		}
		select {
		case <-c.shutdown:
			_ = c.Conn.SetWriteDeadline(time.Now().Add(10 * time.Second))
			_ = c.Conn.WriteMessage(websocket.CloseMessage,
				websocket.FormatCloseMessage(websocket.CloseGoingAway, c.closeReason))
			return
		case message, ok := <-c.Send:
			_ = c.Conn.SetWriteDeadline(time.Now().Add(10 * time.Second))
			if !ok {
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
