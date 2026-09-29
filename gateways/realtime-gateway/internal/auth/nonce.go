package auth

import (
	"context"
	"sync"
	"time"
)

// NonceStore enforces single use across all gateway instances.
type NonceStore interface {
	Consume(ctx context.Context, nonce string, ticketExpireAt time.Time) (bool, error)
}

// MemoryNonceStore is for local tests and single-process development only.
// Entries are dropped by GC once their ticket has expired, so the map stays
// bounded by the number of tickets issued per TTL window (60s).
type MemoryNonceStore struct {
	mu      sync.Mutex
	entries map[string]time.Time // nonce -> ticket expireAt
}

func NewMemoryNonceStore() *MemoryNonceStore {
	return &MemoryNonceStore{entries: make(map[string]time.Time)}
}

// Consume atomically marks a nonce as used. It returns false if the nonce was
// already consumed (replay), empty, or expired.
func (s *MemoryNonceStore) Consume(ctx context.Context, nonce string, ticketExpireAt time.Time) (bool, error) {
	if err := ctx.Err(); err != nil {
		return false, err
	}
	if nonce == "" || !ticketExpireAt.After(time.Now()) {
		return false, nil
	}

	s.mu.Lock()
	defer s.mu.Unlock()

	if _, used := s.entries[nonce]; used {
		return false, nil
	}
	s.entries[nonce] = ticketExpireAt
	return true, nil
}

// GC removes entries whose tickets have expired; expired tickets are rejected
// by signature verification anyway, so their nonces no longer need tracking.
func (s *MemoryNonceStore) GC() {
	now := time.Now()

	s.mu.Lock()
	defer s.mu.Unlock()

	for nonce, expireAt := range s.entries {
		if expireAt.Before(now) {
			delete(s.entries, nonce)
		}
	}
}
