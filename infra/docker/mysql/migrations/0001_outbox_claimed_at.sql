-- 0001: Outbox claim support.
-- OutboxRepository::claimPending() marks rows status=2 with a claimed_at
-- timestamp; releaseStaleClaims() reaps rows whose worker died mid-publish.
-- Applied to every logical database that owns an outbox_events table.

ALTER TABLE `auth_db`.`outbox_events`
  ADD COLUMN `claimed_at` DATETIME(3) NULL AFTER `available_at`;

ALTER TABLE `user_db`.`outbox_events`
  ADD COLUMN `claimed_at` DATETIME(3) NULL AFTER `available_at`;

ALTER TABLE `content_db`.`outbox_events`
  ADD COLUMN `claimed_at` DATETIME(3) NULL AFTER `available_at`;

ALTER TABLE `social_db`.`outbox_events`
  ADD COLUMN `claimed_at` DATETIME(3) NULL AFTER `available_at`;

ALTER TABLE `chat_db`.`outbox_events`
  ADD COLUMN `claimed_at` DATETIME(3) NULL AFTER `available_at`;

ALTER TABLE `message_db`.`outbox_events`
  ADD COLUMN `claimed_at` DATETIME(3) NULL AFTER `available_at`;

INSERT INTO `meta`.`schema_migrations` (name, applied_at)
VALUES ('0001_outbox_claimed_at.sql', NOW(3));
