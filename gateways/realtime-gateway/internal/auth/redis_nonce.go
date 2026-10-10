package auth

import (
	"context"
	"time"

	"github.com/redis/go-redis/v9"
)

// RedisNonceStore 使用带 TTL 的原子 SET NX，在所有副本之间共享消费状态。
type RedisNonceStore struct {
	client *redis.Client
}

func NewRedisNonceStore(client *redis.Client) *RedisNonceStore {
	return &RedisNonceStore{client: client}
}

func (s *RedisNonceStore) Consume(ctx context.Context, nonce string, ticketExpireAt time.Time) (bool, error) {
	if nonce == "" {
		return false, nil
	}
	ttl := time.Until(ticketExpireAt)
	if ttl <= 0 {
		return false, nil
	}
	return s.client.SetNX(ctx, "ws:ticket:nonce:"+nonce, "1", ttl).Result()
}
