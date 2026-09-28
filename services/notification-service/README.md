# NotificationService

## 1. Responsibility
- In-app notifications and offline push (APNs / FCM abstraction).
- User notification preferences and frequency control.

## 2. Owned Data
- Notification records and preferences.

## 3. gRPC API
- `notification.v1.NotificationService/SendPush`

## 4. Consumed Events
- `notification.requested.v1`
- `message.created.v1`
- `like.created.v1`
- `comment.created.v1`
