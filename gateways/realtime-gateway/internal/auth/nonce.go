package auth

import (
	"context"
	"sync"
	"time"
)

// NonceStore 在所有网关实例之间保证随机数只能使用一次。
type NonceStore interface {
	Consume(ctx context.Context, nonce string, ticketExpireAt time.Time) (bool, error)
}

// MemoryNonceStore 仅用于本地测试和单进程开发。票据过期后由 GC 清理条目，使映射大小受每个 TTL 窗口（60 秒）内签
// 发票据数量的限制。
type MemoryNonceStore struct {
	mu      sync.Mutex
	entries map[string]time.Time // 随机数 -> 票据过期时间 expireAt
}

func NewMemoryNonceStore() *MemoryNonceStore {
	return &MemoryNonceStore{entries: make(map[string]time.Time)}
}

// Consume 原子标记随机数已使用；若随机数已消费（重放）、为空或已过期，则返回 false。
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

// GC 删除已过期票据的条目；签名校验也会拒绝过期票据，因此无须继续跟踪其随机数。
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
