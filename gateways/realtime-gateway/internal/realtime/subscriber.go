// Package realtime 把消息领域事件从 Redis PubSub 投递到在线 WebSocket 连接。
package realtime

import (
	"bytes"
	"context"
	"encoding/json"
	"errors"
	"sync"
	"time"

	"github.com/redis/go-redis/v9"
	"social-backend/gateways/realtime-gateway/internal/connection"
	"social-backend/packages/go/logging"
	"social-backend/packages/go/protocol"
)

// Channel 是 message.created.v1 下游消费者发布实时投递事件的 Redis PubSub 频道。
// 载荷约定：{"event_id":"...","recipients":["<user_id>"],"message":{PublicMessage 公共字段}}。
const Channel = "realtime:message.created"

const (
	initialReconnectDelay = 250 * time.Millisecond
	maxReconnectDelay     = 5 * time.Second
)

// messageEvent 是 realtime:message.created 的载荷；message 原样成为 MESSAGE_PUSH 封装
// 的信封 payload（bytes，JSON 序列化为 base64）。
type messageEvent struct {
	EventID    string          `json:"event_id"`
	Recipients []string        `json:"recipients"`
	Message    json.RawMessage `json:"message"`
}

// Subscriber 订阅 Redis PubSub 频道，并把每个事件推送给 recipients 中用户的全部在线连接。
// 投递是尽力而为：非法载荷只记录日志并忽略，发布丢失由客户端重新调用同步接口补齐。
type Subscriber struct {
	client  *redis.Client
	hub     *connection.Hub
	logger  *logging.Logger
	channel string

	mu     sync.Mutex
	pubsub *redis.PubSub
	active bool
}

func NewSubscriber(client *redis.Client, hub *connection.Hub, logger *logging.Logger) *Subscriber {
	return &Subscriber{client: client, hub: hub, logger: logger, channel: Channel}
}

// Run 持续消费频道，连接中断时退避重连；仅在 ctx 取消后返回 nil。
func (s *Subscriber) Run(ctx context.Context) error {
	delay := initialReconnectDelay
	for {
		confirmed, err := s.consume(ctx)
		if ctx.Err() != nil {
			return nil
		}
		// 订阅确认过说明连接曾经可用，重连退避从初始值重新开始。
		if confirmed {
			delay = initialReconnectDelay
		}
		s.logger.Warn("", "", "", "Realtime PubSub disconnected; reconnecting", map[string]any{
			"channel":  s.channel,
			"error":    errorText(err),
			"retry_ms": delay.Milliseconds(),
		})
		select {
		case <-ctx.Done():
			return nil
		case <-time.After(delay):
		}
		if delay < maxReconnectDelay {
			delay *= 2
			if delay > maxReconnectDelay {
				delay = maxReconnectDelay
			}
		}
	}
}

// Healthy 报告订阅连接是否可用；readiness 依赖它在实例无法投递实时消息时摘除流量。
func (s *Subscriber) Healthy(ctx context.Context) error {
	s.mu.Lock()
	active, pubsub := s.active, s.pubsub
	s.mu.Unlock()
	if !active || pubsub == nil {
		return errors.New("realtime subscriber is not subscribed")
	}

	return pubsub.Ping(ctx)
}

// consume 完成一次订阅并消费消息；confirmed 表示 SUBSCRIBE 已确认。
func (s *Subscriber) consume(ctx context.Context) (bool, error) {
	pubsub := s.client.Subscribe(ctx, s.channel)
	defer func() {
		s.mu.Lock()
		s.pubsub = nil
		s.active = false
		s.mu.Unlock()
		_ = pubsub.Close()
	}()

	// 等待 SUBSCRIBE 确认后再标记健康，避免订阅真正生效前 readiness 放行。
	if _, err := pubsub.Receive(ctx); err != nil {
		return false, err
	}
	s.mu.Lock()
	s.pubsub = pubsub
	s.active = true
	s.mu.Unlock()

	messages := pubsub.Channel()
	for {
		select {
		case <-ctx.Done():
			return true, nil
		case message, ok := <-messages:
			if !ok {
				return true, errors.New("pubsub message channel closed")
			}
			s.dispatch(message.Payload)
		}
	}
}

// dispatch 解析载荷并投递；任何非法载荷都不得影响订阅或推送其他事件。
func (s *Subscriber) dispatch(payload string) {
	var event messageEvent
	if err := json.Unmarshal([]byte(payload), &event); err != nil {
		s.logger.Warn("", "", "", "Ignoring malformed realtime message event", map[string]any{
			"channel": s.channel,
			"error":   err.Error(),
		})
		return
	}
	if event.EventID == "" || len(event.Message) == 0 || bytes.Equal(event.Message, []byte("null")) {
		s.logger.Warn("", "", "", "Ignoring realtime message event without event_id or message", map[string]any{
			"channel": s.channel,
		})
		return
	}
	recipients := validRecipients(event.Recipients)
	if len(recipients) == 0 {
		s.logger.Warn("", "", "", "Ignoring realtime message event without valid recipients", map[string]any{
			"channel":  s.channel,
			"event_id": event.EventID,
		})
		return
	}
	envelope, err := json.Marshal(protocol.Envelope{
		Version:     1,
		RequestID:   event.EventID,
		TimestampMs: time.Now().UnixMilli(),
		Type:        protocol.EventMessagePush,
		Payload:     event.Message,
	})
	if err != nil {
		s.logger.Error("", "", "", "Realtime message envelope encoding failed", map[string]any{
			"channel":  s.channel,
			"event_id": event.EventID,
			"error":    err.Error(),
		})
		return
	}
	delivered := 0
	for _, userID := range recipients {
		if s.hub.PushToUser(userID, envelope) {
			delivered++
		}
	}
	s.logger.Debug("", "", "", "Realtime message pushed", map[string]any{
		"channel":    s.channel,
		"event_id":   event.EventID,
		"recipients": len(recipients),
		"delivered":  delivered,
	})
}

// validRecipients 过滤非法用户 ID 并去重：只接受 [1-9][0-9]{0,19}。
func validRecipients(recipients []string) []string {
	seen := make(map[string]struct{}, len(recipients))
	valid := make([]string, 0, len(recipients))
	for _, userID := range recipients {
		if !isUserID(userID) {
			continue
		}
		if _, duplicate := seen[userID]; duplicate {
			continue
		}
		seen[userID] = struct{}{}
		valid = append(valid, userID)
	}

	return valid
}

func isUserID(value string) bool {
	if value == "" || len(value) > 20 || value[0] == '0' {
		return false
	}
	for i := 0; i < len(value); i++ {
		if value[i] < '0' || value[i] > '9' {
			return false
		}
	}

	return true
}

func errorText(err error) string {
	if err == nil {
		return "unknown"
	}

	return err.Error()
}
