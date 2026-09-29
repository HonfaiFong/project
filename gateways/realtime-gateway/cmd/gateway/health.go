package main

import (
	"context"
	"encoding/json"
	"net/http"
	"time"

	"github.com/redis/go-redis/v9"
)

type redisPinger interface {
	Ping(context.Context) *redis.StatusCmd
}

func newReadinessHandler(client redisPinger) http.Handler {
	return http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		ctx, cancel := context.WithTimeout(r.Context(), 300*time.Millisecond)
		defer cancel()
		w.Header().Set("Content-Type", "application/json")
		if err := client.Ping(ctx).Err(); err != nil {
			w.WriteHeader(http.StatusServiceUnavailable)
			_ = json.NewEncoder(w).Encode(map[string]string{"status": "NOT_SERVING", "service": "realtime-gateway"})
			return
		}
		_ = json.NewEncoder(w).Encode(map[string]string{"status": "SERVING", "service": "realtime-gateway"})
	})
}
