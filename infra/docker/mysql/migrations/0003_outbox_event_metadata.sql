-- 随事件持久化消息代理的排序键和原始请求追踪信息。
-- 现有的人格事件使用载荷中的 user_id 作为排序键；其他事件沿用 aggregate_id。历史事件的追踪信息未知。
ALTER TABLE `auth_db`.`outbox_events`
  ADD COLUMN `event_key` VARCHAR(64) NOT NULL DEFAULT '' AFTER `aggregate_id`,
  ADD COLUMN `trace_id` VARCHAR(64) NOT NULL DEFAULT '' AFTER `event_key`;
ALTER TABLE `user_db`.`outbox_events`
  ADD COLUMN `event_key` VARCHAR(64) NOT NULL DEFAULT '' AFTER `aggregate_id`,
  ADD COLUMN `trace_id` VARCHAR(64) NOT NULL DEFAULT '' AFTER `event_key`;
ALTER TABLE `content_db`.`outbox_events`
  ADD COLUMN `event_key` VARCHAR(64) NOT NULL DEFAULT '' AFTER `aggregate_id`,
  ADD COLUMN `trace_id` VARCHAR(64) NOT NULL DEFAULT '' AFTER `event_key`;
ALTER TABLE `social_db`.`outbox_events`
  ADD COLUMN `event_key` VARCHAR(64) NOT NULL DEFAULT '' AFTER `aggregate_id`,
  ADD COLUMN `trace_id` VARCHAR(64) NOT NULL DEFAULT '' AFTER `event_key`;
ALTER TABLE `chat_db`.`outbox_events`
  ADD COLUMN `event_key` VARCHAR(64) NOT NULL DEFAULT '' AFTER `aggregate_id`,
  ADD COLUMN `trace_id` VARCHAR(64) NOT NULL DEFAULT '' AFTER `event_key`;
ALTER TABLE `message_db`.`outbox_events`
  ADD COLUMN `event_key` VARCHAR(64) NOT NULL DEFAULT '' AFTER `aggregate_id`,
  ADD COLUMN `trace_id` VARCHAR(64) NOT NULL DEFAULT '' AFTER `event_key`;

UPDATE `auth_db`.`outbox_events` SET `event_key` = `aggregate_id`;
UPDATE `user_db`.`outbox_events`
SET `event_key` = CASE
  WHEN `event_type` = 'persona.updated.v1' AND JSON_UNQUOTE(JSON_EXTRACT(`payload`, '$.user_id')) IS NOT NULL
    THEN JSON_UNQUOTE(JSON_EXTRACT(`payload`, '$.user_id'))
  ELSE `aggregate_id`
END;
UPDATE `content_db`.`outbox_events` SET `event_key` = `aggregate_id`;
UPDATE `social_db`.`outbox_events` SET `event_key` = `aggregate_id`;
UPDATE `chat_db`.`outbox_events` SET `event_key` = `aggregate_id`;
UPDATE `message_db`.`outbox_events` SET `event_key` = `aggregate_id`;
