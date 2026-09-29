package auth

import (
	"context"
	"testing"
	"time"

	"github.com/alicebob/miniredis/v2"
	"github.com/redis/go-redis/v9"
)

func TestRedisNonceStoreRejectsReplayAcrossClients(t *testing.T) {
	server := miniredis.RunT(t)
	firstClient := redis.NewClient(&redis.Options{Addr: server.Addr()})
	secondClient := redis.NewClient(&redis.Options{Addr: server.Addr()})
	t.Cleanup(func() { _ = firstClient.Close(); _ = secondClient.Close() })
	first := NewRedisNonceStore(firstClient)
	second := NewRedisNonceStore(secondClient)
	ctx := context.Background()
	expiresAt := time.Now().Add(time.Minute)

	if ok, err := first.Consume(ctx, "ticket-1", expiresAt); err != nil || !ok {
		t.Fatalf("first consume: ok=%v err=%v", ok, err)
	}
	if ok, err := second.Consume(ctx, "ticket-1", expiresAt); err != nil || ok {
		t.Fatalf("cross-instance replay: ok=%v err=%v", ok, err)
	}
	if ok, err := second.Consume(ctx, "ticket-2", expiresAt); err != nil || !ok {
		t.Fatalf("different ticket: ok=%v err=%v", ok, err)
	}
	if ttl := server.TTL("ws:ticket:nonce:ticket-1"); ttl <= 0 || ttl > time.Minute {
		t.Fatalf("unexpected nonce TTL: %s", ttl)
	}
}

func TestRedisNonceStoreFailsClosedWhenRedisUnavailable(t *testing.T) {
	server := miniredis.RunT(t)
	client := redis.NewClient(&redis.Options{Addr: server.Addr(), MaxRetries: 0})
	t.Cleanup(func() { _ = client.Close() })
	server.Close()
	store := NewRedisNonceStore(client)
	ctx, cancel := context.WithTimeout(context.Background(), time.Second)
	defer cancel()

	if ok, err := store.Consume(ctx, "ticket-1", time.Now().Add(time.Minute)); err == nil || ok {
		t.Fatalf("expected Redis failure, got ok=%v err=%v", ok, err)
	}
}
