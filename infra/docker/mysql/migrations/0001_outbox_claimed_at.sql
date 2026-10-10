-- 0001：支持 Outbox 事件领取。
-- OutboxRepository::claimPending() 将行标记为 status=2 并写入 claimed_at 时间戳；releaseStaleClaims() 回收发布过
-- 程中工作进程崩溃留下的行。
-- 应用于所有拥有 outbox_events 表的逻辑数据库。

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

