# SearchService

## 1. Responsibility
- OpenSearch search queries for posts, personas, and topics.
- Async index synchronization via RabbitMQ Streams events.

## 2. Owned Data
- OpenSearch indices.

## 3. Consumed Events
- `post.approved.v1`
- `post.deleted.v1`
- `persona.updated.v1`
