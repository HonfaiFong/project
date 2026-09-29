package auth

import (
	"context"
	"testing"
	"time"
)

func TestNonceStoreConsumeAllowsSingleUse(t *testing.T) {
	store := NewMemoryNonceStore()
	expire := time.Now().Add(time.Minute)
	ctx := context.Background()

	if ok, err := store.Consume(ctx, "nonce-1", expire); err != nil || !ok {
		t.Fatal("expected first consume to succeed")
	}
	if ok, err := store.Consume(ctx, "nonce-1", expire); err != nil || ok {
		t.Fatal("expected replay of nonce-1 to be rejected")
	}
	if ok, err := store.Consume(ctx, "nonce-2", expire); err != nil || !ok {
		t.Fatal("expected unrelated nonce to be accepted")
	}
}

func TestNonceStoreRejectsEmptyNonce(t *testing.T) {
	store := NewMemoryNonceStore()
	if ok, err := store.Consume(context.Background(), "", time.Now().Add(time.Minute)); err != nil || ok {
		t.Fatal("expected empty nonce to be rejected")
	}
}

func TestNonceStoreGCDropsExpiredEntries(t *testing.T) {
	store := NewMemoryNonceStore()
	store.entries["stale"] = time.Now().Add(-time.Second)
	store.entries["fresh"] = time.Now().Add(time.Minute)

	store.GC()

	store.mu.Lock()
	_, staleLeft := store.entries["stale"]
	_, freshLeft := store.entries["fresh"]
	store.mu.Unlock()

	if staleLeft {
		t.Fatal("expected expired nonce to be collected")
	}
	if !freshLeft {
		t.Fatal("expected live nonce to survive GC")
	}
}
