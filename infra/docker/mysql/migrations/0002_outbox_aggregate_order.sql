-- 支持中继按聚合查询前序事件。
ALTER TABLE `auth_db`.`outbox_events` ADD INDEX `idx_aggregate_order` (`aggregate_type`, `aggregate_id`, `id`, `status`);
ALTER TABLE `user_db`.`outbox_events` ADD INDEX `idx_aggregate_order` (`aggregate_type`, `aggregate_id`, `id`, `status`);
ALTER TABLE `content_db`.`outbox_events` ADD INDEX `idx_aggregate_order` (`aggregate_type`, `aggregate_id`, `id`, `status`);
ALTER TABLE `social_db`.`outbox_events` ADD INDEX `idx_aggregate_order` (`aggregate_type`, `aggregate_id`, `id`, `status`);
ALTER TABLE `chat_db`.`outbox_events` ADD INDEX `idx_aggregate_order` (`aggregate_type`, `aggregate_id`, `id`, `status`);
ALTER TABLE `message_db`.`outbox_events` ADD INDEX `idx_aggregate_order` (`aggregate_type`, `aggregate_id`, `id`, `status`);
