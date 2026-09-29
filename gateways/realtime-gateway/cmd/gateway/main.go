package main

import (
	"context"
	"encoding/json"
	"fmt"
	"log"
	"net/http"
	"os"
	"os/signal"
	"syscall"
	"time"

	"github.com/redis/go-redis/v9"
	"social-backend/gateways/realtime-gateway/internal/auth"
	"social-backend/gateways/realtime-gateway/internal/connection"
	"social-backend/packages/go/logging"
)

func main() {
	port := os.Getenv("GATEWAY_PORT")
	if port == "" {
		port = "8080"
	}
	secret := os.Getenv("WS_TICKET_SECRET")
	if secret == "" {
		log.Fatal("WS_TICKET_SECRET env is required (minimum 32 bytes, shared with auth-service)")
	}
	if len(secret) < 32 {
		log.Fatal("WS_TICKET_SECRET must be at least 32 bytes")
	}

	logger := logging.NewLogger("realtime-gateway", "gw-01")
	hub := connection.NewHub()
	go hub.Run()

	redisAddr := os.Getenv("REDIS_HOST")
	if redisAddr == "" {
		log.Fatal("REDIS_HOST is required for shared WS ticket replay protection")
	}
	redisClient := redis.NewClient(&redis.Options{Addr: redisAddr})
	defer redisClient.Close()
	ctx, cancel := context.WithTimeout(context.Background(), 5*time.Second)
	if err := redisClient.Ping(ctx).Err(); err != nil {
		log.Fatalf("Redis unavailable for WS ticket replay protection: %v", err)
	}
	cancel()
	var nonces auth.NonceStore = auth.NewRedisNonceStore(redisClient)

	http.HandleFunc("/live", func(w http.ResponseWriter, r *http.Request) {
		w.Header().Set("Content-Type", "application/json")
		_ = json.NewEncoder(w).Encode(map[string]any{
			"status":  "SERVING",
			"service": "realtime-gateway",
		})
	})
	readiness := newReadinessHandler(redisClient)
	http.Handle("/ready", readiness)
	http.Handle("/health", readiness)

	http.Handle("/ws", newWSHandler(secret, nonces, hub, logger))

	server := &http.Server{
		Addr: ":" + port,
	}

	go func() {
		logger.Info("", "", "", fmt.Sprintf("Realtime Gateway listening on :%s", port), nil)
		if err := server.ListenAndServe(); err != nil && err != http.ErrServerClosed {
			log.Fatalf("Gateway listen error: %v", err)
		}
	}()

	quit := make(chan os.Signal, 1)
	signal.Notify(quit, syscall.SIGINT, syscall.SIGTERM)
	<-quit

	logger.Info("", "", "", "Shutting down Realtime Gateway...", nil)
}
