package main

import (
	"context"
	"errors"
	"net/http"
	"net/http/httptest"
	"testing"

	"github.com/redis/go-redis/v9"
	"social-backend/gateways/realtime-gateway/internal/connection"
)

type readinessPinger struct{ err error }

func (p readinessPinger) Ping(context.Context) *redis.StatusCmd {
	cmd := redis.NewStatusCmd(context.Background())
	if p.err != nil {
		cmd.SetErr(p.err)
	} else {
		cmd.SetVal("PONG")
	}
	return cmd
}

func TestReadinessFollowsRedis(t *testing.T) {
	for _, tc := range []struct {
		name string
		err  error
		want int
	}{
		{"available", nil, http.StatusOK},
		{"unavailable", errors.New("redis down"), http.StatusServiceUnavailable},
	} {
		t.Run(tc.name, func(t *testing.T) {
			response := httptest.NewRecorder()
			newReadinessHandler(readinessPinger{tc.err}, connection.NewHub()).ServeHTTP(response, httptest.NewRequest(http.MethodGet, "/ready", nil))
			if response.Code != tc.want {
				t.Fatalf("status = %d, want %d", response.Code, tc.want)
			}
		})
	}
}

func TestReadinessStopsDuringDrain(t *testing.T) {
	hub := connection.NewHub()
	hub.StartDraining()
	response := httptest.NewRecorder()
	newReadinessHandler(readinessPinger{}, hub).ServeHTTP(response, httptest.NewRequest(http.MethodGet, "/ready", nil))
	if response.Code != http.StatusServiceUnavailable {
		t.Fatalf("status = %d, want 503", response.Code)
	}
}
