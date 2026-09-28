package auth

import (
	"sync"
	"time"
)

// NonceStore enforces single use of WS ticket nonces within a ticket's TTL.
// Entries are dropped by GC once their ticket has expired, so the map stays
// bounded by the number of tickets issued per TTL window (60s).
type NonceStore struct {
	mu      sync.Mutex
	entries map[string]time.Time // nonce -> ticket expireAt
}

func NewNonceStore() *NonceStore {
	return &NonceStore{entries: make(map[string]time.Time)}
}

// Consume atomically marks a nonce as used. It returns false if the nonce was
// already consumed (replay) or is empty.
func (s *NonceStore) Consume(nonce string, ticketExpireAt time.Time) bool {
	if nonce == "" {
		return false
	}

	s.mu.Lock()
	defer s.mu.Unlock()

	if _, used := s.entries[nonce]; used {
		return false
	}
	s.entries[nonce] = ticketExpireAt
	return true
}

// GC removes entries whose tickets have expired; expired tickets are rejected
// by signature verification anyway, so their nonces no longer need tracking.
func (s *NonceStore) GC() {
	now := time.Now()

	s.mu.Lock()
	defer s.mu.Unlock()

	for nonce, expireAt := range s.entries {
		if expireAt.Before(now) {
			delete(s.entries, nonce)
		}
	}
}
