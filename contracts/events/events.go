package events

import (
	"encoding/json"
	"time"
)

const (
	UserRegistered = "user.registered.v1"
	UserUpdated    = "user.updated.v1"
	PersonaUpdated = "persona.updated.v1"

	PostCreated    = "post.created.v1"
	PostApproved   = "post.approved.v1"
	PostDeleted    = "post.deleted.v1"
	CommentCreated = "comment.created.v1"

	LikeCreated   = "like.created.v1"
	FollowCreated = "follow.created.v1"

	ConversationCreated = "conversation.created.v1"

	MessageCreated   = "message.created.v1"
	MessageDelivered = "message.delivered.v1"
	MessageRead      = "message.read.v1"

	ReportCreated       = "report.created.v1"
	ModerationCompleted = "moderation.completed.v1"

	NotificationRequested = "notification.requested.v1"

	AnalyticsEvent = "analytics.event.v1"
)

type EventEnvelope struct {
	EventID      string         `json:"event_id"`
	EventType    string         `json:"event_type"`
	EventVersion int            `json:"event_version"`
	OccurredAt   string         `json:"occurred_at"`
	TraceID      string         `json:"trace_id"`
	Producer     string         `json:"producer"`
	Key          string         `json:"key"`
	Payload      map[string]any `json:"payload"`
}

func NewEventEnvelope(eventID, eventType, producer, key, traceID string, payload map[string]any) *EventEnvelope {
	return &EventEnvelope{
		EventID:      eventID,
		EventType:    eventType,
		EventVersion: 1,
		OccurredAt:   time.Now().UTC().Format(time.RFC3339),
		TraceID:      traceID,
		Producer:     producer,
		Key:          key,
		Payload:      payload,
	}
}

func (e *EventEnvelope) ToJSON() ([]byte, error) {
	return json.Marshal(e)
}
