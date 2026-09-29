package main

import (
	"context"
	"encoding/json"
	"net/http"
	"time"

	"github.com/redis/go-redis/v9"
	"social-backend/gateways/realtime-gateway/internal/connection"
)

type redisPinger interface {
	Ping(context.Context) *redis.StatusCmd
}

func newReadinessHandler(client redisPinger, hub *connection.Hub) http.Handler {
	return http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.Header().Set("Content-Type", "application/json")
		if hub.IsDraining() {
			w.WriteHeader(http.StatusServiceUnavailable)
			_ = json.NewEncoder(w).Encode(map[string]string{"status": "NOT_SERVING", "service": "realtime-gateway"})
			return
		}
		ctx, cancel := context.WithTimeout(r.Context(), 300*time.Millisecond)
		defer cancel()
		if err := client.Ping(ctx).Err(); err != nil {
			w.WriteHeader(http.StatusServiceUnavailable)
			_ = json.NewEncoder(w).Encode(map[string]string{"status": "NOT_SERVING", "service": "realtime-gateway"})
			return
		}
		_ = json.NewEncoder(w).Encode(map[string]string{"status": "SERVING", "service": "realtime-gateway"})
	})
}
