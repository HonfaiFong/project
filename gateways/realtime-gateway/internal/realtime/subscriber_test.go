package realtime

import (
	"context"
	"encoding/json"
	"net"
	"testing"
	"time"

	"github.com/alicebob/miniredis/v2"
	"github.com/redis/go-redis/v9"
	"social-backend/gateways/realtime-gateway/internal/connection"
	"social-backend/packages/go/logging"
	"social-backend/packages/go/protocol"
)

const validPayload = `{"event_id":"evt-1","recipients":["1001","1002","1002","not-a-user","007",""],` +
	`"message":{"message_id":"55","conversation_id":"9","seq":"3","client_message_id":"client-1",` +
	`"type":1,"content":"{\"body\":\"hi\"}","created_at":"2026-10-12 00:00:00.000",` +
	`"author":{"identity_mode":1,"persona_id":"77","display_name":"Alice","avatar_key":"",` +
	`"anonymous_ref":"","profile_accessible":true}}}`

func TestSubscriberPushesOnlyToOnlineRecipients(t *testing.T) {
	server := miniredis.RunT(t)
	client := redis.NewClient(&redis.Options{Addr: server.Addr()})
	t.Cleanup(func() { _ = client.Close() })
	hub := connection.NewHub()
	alicePhone := registerClient(t, hub, "1001", "alice-phone")
	aliceLaptop := registerClient(t, hub, "1001", "alice-laptop")
	bob := registerClient(t, hub, "1002", "bob-phone")
	carol := registerClient(t, hub, "1003", "carol-phone")
	startSubscriber(t, client, hub)

	if err := client.Publish(context.Background(), Channel, validPayload).Err(); err != nil {
		t.Fatalf("publish: %v", err)
	}

	for _, target := range []*connection.Client{alicePhone, aliceLaptop, bob} {
		envelope := receiveEnvelope(t, target)
		if envelope.Type != protocol.EventMessagePush {
			t.Fatalf("type = %q, want %q", envelope.Type, protocol.EventMessagePush)
		}
		if envelope.RequestID != "evt-1" {
			t.Fatalf("request_id = %q, want the event id", envelope.RequestID)
		}
		var message map[string]any
		if err := json.Unmarshal(envelope.Payload, &message); err != nil {
			t.Fatalf("payload is not the message JSON: %v", err)
		}
		if message["message_id"] != "55" || message["conversation_id"] != "9" {
			t.Fatalf("payload message = %v, want the published message", message)
		}
		if len(target.Send) != 0 {
			t.Fatal("duplicate recipients must not push twice")
		}
	}
	// 会话成员之外的用户以及非法/重复的 recipient 都不得收到任何推送。
	expectNoMessage(t, carol)
}

func TestSubscriberIgnoresInvalidPayloads(t *testing.T) {
	server := miniredis.RunT(t)
	client := redis.NewClient(&redis.Options{Addr: server.Addr()})
	t.Cleanup(func() { _ = client.Close() })
	hub := connection.NewHub()
	member := registerClient(t, hub, "1001", "member-phone")
	startSubscriber(t, client, hub)

	for _, payload := range []string{
		`not-json`,
		`{"recipients":["1001"],"message":{"message_id":"1"}}`,
		`{"event_id":"evt-empty","recipients":["1001"],"message":null}`,
		`{"event_id":"evt-no-recipients","recipients":[],"message":{"message_id":"1"}}`,
		`{"event_id":"evt-bad-recipients","recipients":["007","-1","abc"],"message":{"message_id":"1"}}`,
		`{"event_id":"evt-wrong-types","recipients":"1001","message":{"message_id":"1"}}`,
	} {
		if err := client.Publish(context.Background(), Channel, payload).Err(); err != nil {
			t.Fatalf("publish %s: %v", payload, err)
		}
	}
	expectNoMessage(t, member)

	// 非法载荷之后订阅必须仍能正常投递合法事件。
	if err := client.Publish(context.Background(), Channel, validPayload).Err(); err != nil {
		t.Fatalf("publish valid payload: %v", err)
	}
	if envelope := receiveEnvelope(t, member); envelope.RequestID != "evt-1" {
		t.Fatalf("request_id = %q, want evt-1", envelope.RequestID)
	}
}

func TestSubscriberHealthFollowsSubscriptionState(t *testing.T) {
	server := miniredis.RunT(t)
	client := redis.NewClient(&redis.Options{Addr: server.Addr()})
	t.Cleanup(func() { _ = client.Close() })
	subscriber := NewSubscriber(client, connection.NewHub(), logging.NewLogger("test", "test"))

	ctx, cancel := context.WithCancel(context.Background())
	defer cancel()
	if err := subscriber.Healthy(context.Background()); err == nil {
		t.Fatal("subscriber reported healthy before Run")
	}
	done := make(chan error, 1)
	go func() { done <- subscriber.Run(ctx) }()
	waitForHealthy(t, subscriber)

	cancel()
	select {
	case err := <-done:
		if err != nil {
			t.Fatalf("subscriber stopped with error: %v", err)
		}
	case <-time.After(2 * time.Second):
		t.Fatal("subscriber did not stop after context cancellation")
	}
	if err := subscriber.Healthy(context.Background()); err == nil {
		t.Fatal("subscriber stayed healthy after Run returned")
	}
}

func TestSubscriberReconnectsWhenRedisBecomesAvailable(t *testing.T) {
	addr := reserveClosedAddress(t)
	// 首次订阅必然失败；退避重连后应在 Redis 恢复时重新订阅并继续投递。
	client := redis.NewClient(&redis.Options{Addr: addr, MaxRetries: -1, DialTimeout: 200 * time.Millisecond})
	t.Cleanup(func() { _ = client.Close() })
	hub := connection.NewHub()
	member := registerClient(t, hub, "1001", "member-phone")
	subscriber := NewSubscriber(client, hub, logging.NewLogger("test", "test"))

	ctx, cancel := context.WithCancel(context.Background())
	done := make(chan error, 1)
	go func() { done <- subscriber.Run(ctx) }()
	t.Cleanup(func() {
		cancel()
		select {
		case err := <-done:
			if err != nil {
				t.Errorf("subscriber stopped with error: %v", err)
			}
		case <-time.After(2 * time.Second):
			t.Error("subscriber did not stop")
		}
	})
	if err := subscriber.Healthy(context.Background()); err == nil {
		t.Fatal("subscriber reported healthy while Redis is not reachable")
	}

	server := miniredis.NewMiniRedis()
	if err := server.StartAddr(addr); err != nil {
		t.Fatalf("start miniredis on %s: %v", addr, err)
	}
	t.Cleanup(server.Close)
	waitForHealthy(t, subscriber)

	if err := client.Publish(context.Background(), Channel, validPayload).Err(); err != nil {
		t.Fatalf("publish: %v", err)
	}
	if envelope := receiveEnvelope(t, member); envelope.Type != protocol.EventMessagePush {
		t.Fatalf("type = %q, want %q", envelope.Type, protocol.EventMessagePush)
	}
}

func startSubscriber(t *testing.T, client *redis.Client, hub *connection.Hub) *Subscriber {
	t.Helper()
	subscriber := NewSubscriber(client, hub, logging.NewLogger("test", "test"))
	ctx, cancel := context.WithCancel(context.Background())
	done := make(chan error, 1)
	go func() { done <- subscriber.Run(ctx) }()
	t.Cleanup(func() {
		cancel()
		select {
		case err := <-done:
			if err != nil {
				t.Errorf("subscriber stopped with error: %v", err)
			}
		case <-time.After(2 * time.Second):
			t.Error("subscriber did not stop")
		}
	})
	waitForHealthy(t, subscriber)

	return subscriber
}

func waitForHealthy(t *testing.T, subscriber *Subscriber) {
	t.Helper()
	deadline := time.Now().Add(5 * time.Second)
	for time.Now().Before(deadline) {
		ctx, cancel := context.WithTimeout(context.Background(), 200*time.Millisecond)
		err := subscriber.Healthy(ctx)
		cancel()
		if err == nil {
			return
		}
		time.Sleep(10 * time.Millisecond)
	}
	t.Fatal("subscriber did not become healthy")
}

func registerClient(t *testing.T, hub *connection.Hub, userID, connID string) *connection.Client {
	t.Helper()
	client := connection.NewClient(hub, nil, userID, "persona-1", "device-1", connID)
	if !hub.Register(client) {
		t.Fatal("client registration refused")
	}
	t.Cleanup(func() { hub.Unregister(client) })

	return client
}

func receiveEnvelope(t *testing.T, client *connection.Client) protocol.Envelope {
	t.Helper()
	select {
	case raw := <-client.Send:
		var envelope protocol.Envelope
		if err := json.Unmarshal(raw, &envelope); err != nil {
			t.Fatalf("push envelope is not JSON: %v", err)
		}
		return envelope
	case <-time.After(2 * time.Second):
		t.Fatal("expected a realtime push")
		return protocol.Envelope{}
	}
}

func expectNoMessage(t *testing.T, client *connection.Client) {
	t.Helper()
	select {
	case raw := <-client.Send:
		t.Fatalf("unexpected realtime push: %s", raw)
	case <-time.After(150 * time.Millisecond):
	}
}

func reserveClosedAddress(t *testing.T) string {
	t.Helper()
	listener, err := net.Listen("tcp", "127.0.0.1:0")
	if err != nil {
		t.Fatalf("reserve address: %v", err)
	}
	addr := listener.Addr().String()
	if err := listener.Close(); err != nil {
		t.Fatalf("close reserved address: %v", err)
	}

	return addr
}
