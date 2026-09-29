package main

import (
	"context"
	"crypto/tls"
	"encoding/json"
	"errors"
	"fmt"
	"log"
	"net/http"
	"os"
	"os/signal"
	"strconv"
	"syscall"
	"time"

	"github.com/redis/go-redis/v9"
	"social-backend/gateways/realtime-gateway/internal/auth"
	"social-backend/gateways/realtime-gateway/internal/connection"
	"social-backend/packages/go/logging"
)

func main() {
	if err := run(); err != nil {
		log.Print(err)
		os.Exit(1)
	}
}

func run() error {
	port := os.Getenv("GATEWAY_PORT")
	if port == "" {
		port = "8080"
	}
	secret := os.Getenv("WS_TICKET_SECRET")
	if len(secret) < 32 {
		return errors.New("WS_TICKET_SECRET must be at least 32 bytes")
	}
	redisAddr := os.Getenv("REDIS_HOST")
	if redisAddr == "" {
		return errors.New("REDIS_HOST is required for shared WS ticket replay protection")
	}
	redisTLS, err := strconv.ParseBool(envDefault("REDIS_TLS", "false"))
	if err != nil {
		return fmt.Errorf("invalid REDIS_TLS: %w", err)
	}
	redisOptions := &redis.Options{
		Addr:     redisAddr,
		Username: os.Getenv("REDIS_USERNAME"),
		Password: os.Getenv("REDIS_PASSWORD"),
	}
	if redisTLS {
		redisOptions.TLSConfig = &tls.Config{MinVersion: tls.VersionTLS12}
	}
	redisClient := redis.NewClient(redisOptions)
	defer redisClient.Close()
	pingCtx, cancel := context.WithTimeout(context.Background(), 5*time.Second)
	err = redisClient.Ping(pingCtx).Err()
	cancel()
	if err != nil {
		return fmt.Errorf("Redis unavailable for WS ticket replay protection: %w", err)
	}

	logger := logging.NewLogger("realtime-gateway", envDefault("SERVICE_INSTANCE_ID", envDefault("HOSTNAME", "unknown")))
	hub := connection.NewHub()
	mux := http.NewServeMux()
	mux.HandleFunc("/live", func(w http.ResponseWriter, r *http.Request) {
		w.Header().Set("Content-Type", "application/json")
		_ = json.NewEncoder(w).Encode(map[string]string{"status": "SERVING", "service": "realtime-gateway"})
	})
	readiness := newReadinessHandler(redisClient, hub)
	mux.Handle("/ready", readiness)
	mux.Handle("/health", readiness)
	var nonces auth.NonceStore = auth.NewRedisNonceStore(redisClient)
	mux.Handle("/ws", newWSHandler(secret, nonces, hub, logger))
	server := &http.Server{
		Addr:              ":" + port,
		Handler:           mux,
		ReadHeaderTimeout: 5 * time.Second,
		IdleTimeout:       60 * time.Second,
	}
	serverErr := make(chan error, 1)
	go func() { serverErr <- server.ListenAndServe() }()
	logger.Info("", "", "", fmt.Sprintf("Realtime Gateway listening on :%s", port), nil)

	signalCtx, stop := signal.NotifyContext(context.Background(), os.Interrupt, syscall.SIGTERM)
	defer stop()
	var serveErr error
	select {
	case <-signalCtx.Done():
	case serveErr = <-serverErr:
	}
	hub.StartDraining()
	if errors.Is(serveErr, http.ErrServerClosed) {
		serveErr = nil
	}
	logger.Info("", "", "", "Shutting down Realtime Gateway...", nil)
	shutdownCtx, shutdownCancel := context.WithTimeout(context.Background(), 20*time.Second)
	defer shutdownCancel()
	if err := server.Shutdown(shutdownCtx); err != nil {
		_ = server.Close()
		serveErr = errors.Join(serveErr, fmt.Errorf("HTTP shutdown: %w", err))
	}
	if err := hub.Drain(shutdownCtx); err != nil {
		serveErr = errors.Join(serveErr, fmt.Errorf("WebSocket drain: %w", err))
	}
	if serveErr != nil {
		return fmt.Errorf("Gateway listen error: %w", serveErr)
	}
	return nil
}

func envDefault(name, fallback string) string {
	if value := os.Getenv(name); value != "" {
		return value
	}
	return fallback
}
