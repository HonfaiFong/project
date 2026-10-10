-- claimPending 根据排序键和事件 ID 检查更早的未发布行。
ALTER TABLE `auth_db`.`outbox_events` ADD INDEX `idx_event_key_id_status` (`event_key`, `id`, `status`);
ALTER TABLE `user_db`.`outbox_events` ADD INDEX `idx_event_key_id_status` (`event_key`, `id`, `status`);
ALTER TABLE `content_db`.`outbox_events` ADD INDEX `idx_event_key_id_status` (`event_key`, `id`, `status`);
ALTER TABLE `social_db`.`outbox_events` ADD INDEX `idx_event_key_id_status` (`event_key`, `id`, `status`);
ALTER TABLE `chat_db`.`outbox_events` ADD INDEX `idx_event_key_id_status` (`event_key`, `id`, `status`);
ALTER TABLE `message_db`.`outbox_events` ADD INDEX `idx_event_key_id_status` (`event_key`, `id`, `status`);
