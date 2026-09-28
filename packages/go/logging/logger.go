package logging

import (
	"encoding/json"
	"fmt"
	"os"
	"strings"
	"time"
)

type Logger struct {
	ServiceName string
	InstanceID  string
}

func NewLogger(serviceName, instanceID string) *Logger {
	return &Logger{
		ServiceName: serviceName,
		InstanceID:  instanceID,
	}
}

type LogEntry struct {
	Timestamp string         `json:"timestamp"`
	Level     string         `json:"level"`
	Service   string         `json:"service"`
	Instance  string         `json:"instance"`
	TraceID   string         `json:"trace_id"`
	RequestID string         `json:"request_id"`
	UserID    string         `json:"user_id"`
	Message   string         `json:"message"`
	Context   map[string]any `json:"context"`
}

func (l *Logger) Info(traceID, requestID, userID, message string, context map[string]any) {
	l.write("INFO", traceID, requestID, userID, message, context)
}

func (l *Logger) Error(traceID, requestID, userID, message string, context map[string]any) {
	l.write("ERROR", traceID, requestID, userID, message, context)
}

func (l *Logger) Debug(traceID, requestID, userID, message string, context map[string]any) {
	l.write("DEBUG", traceID, requestID, userID, message, context)
}

func (l *Logger) Warn(traceID, requestID, userID, message string, context map[string]any) {
	l.write("WARN", traceID, requestID, userID, message, context)
}

func (l *Logger) write(level, traceID, requestID, userID, message string, context map[string]any) {
	sanitized := make(map[string]any)
	for k, v := range context {
		lowerK := strings.ToLower(k)
		if strings.Contains(lowerK, "token") || strings.Contains(lowerK, "secret") || strings.Contains(lowerK, "password") || strings.Contains(lowerK, "ticket") {
			sanitized[k] = "[REDACTED]"
		} else {
			sanitized[k] = v
		}
	}

	entry := LogEntry{
		Timestamp: time.Now().UTC().Format(time.RFC3339Nano),
		Level:     level,
		Service:   l.ServiceName,
		Instance:  l.InstanceID,
		TraceID:   traceID,
		RequestID: requestID,
		UserID:    userID,
		Message:   message,
		Context:   sanitized,
	}

	data, err := json.Marshal(entry)
	if err == nil {
		fmt.Fprintln(os.Stdout, string(data))
	}
}
