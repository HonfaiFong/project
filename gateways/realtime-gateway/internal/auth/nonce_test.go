package auth

import (
	"testing"
	"time"
)

func TestNonceStoreConsumeAllowsSingleUse(t *testing.T) {
	store := NewNonceStore()
	expire := time.Now().Add(time.Minute)

	if !store.Consume("nonce-1", expire) {
		t.Fatal("expected first consume to succeed")
	}
	if store.Consume("nonce-1", expire) {
		t.Fatal("expected replay of nonce-1 to be rejected")
	}
	if !store.Consume("nonce-2", expire) {
		t.Fatal("expected unrelated nonce to be accepted")
	}
}

func TestNonceStoreRejectsEmptyNonce(t *testing.T) {
	store := NewNonceStore()
	if store.Consume("", time.Now().Add(time.Minute)) {
		t.Fatal("expected empty nonce to be rejected")
	}
}

func TestNonceStoreGCDropsExpiredEntries(t *testing.T) {
	store := NewNonceStore()
	store.Consume("stale", time.Now().Add(-time.Second))
	store.Consume("fresh", time.Now().Add(time.Minute))

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
