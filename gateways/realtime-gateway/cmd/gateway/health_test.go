package main

import (
	"context"
	"errors"
	"net/http"
	"net/http/httptest"
	"sync/atomic"
	"testing"

	"github.com/redis/go-redis/v9"
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
			newReadinessHandler(readinessPinger{tc.err}, &atomic.Bool{}).ServeHTTP(response, httptest.NewRequest(http.MethodGet, "/ready", nil))
			if response.Code != tc.want {
				t.Fatalf("status = %d, want %d", response.Code, tc.want)
			}
		})
	}
}

func TestReadinessStopsDuringDrain(t *testing.T) {
	var draining atomic.Bool
	draining.Store(true)
	response := httptest.NewRecorder()
	newReadinessHandler(readinessPinger{}, &draining).ServeHTTP(response, httptest.NewRequest(http.MethodGet, "/ready", nil))
	if response.Code != http.StatusServiceUnavailable {
		t.Fatalf("status = %d, want 503", response.Code)
	}
}
