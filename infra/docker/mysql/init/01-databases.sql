-- -------------------------------------------------------------
-- 初始化社交后端的逻辑数据库
-- -------------------------------------------------------------
CREATE DATABASE IF NOT EXISTS `auth_db` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE IF NOT EXISTS `user_db` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE IF NOT EXISTS `content_db` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE IF NOT EXISTS `social_db` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE IF NOT EXISTS `chat_db` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE IF NOT EXISTS `message_db` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- 运行时账号和迁移账号由同目录的 02-runtime-accounts.sh 按环境变量创建。
-- 不在基线中把远程 root 暴露给业务容器；结构迁移也不得依赖 root。

-- -------------------------------------------------------------
-- auth_db 表结构
-- -------------------------------------------------------------
USE `auth_db`;

CREATE TABLE IF NOT EXISTS `devices` (
  `id` BIGINT UNSIGNED NOT NULL PRIMARY KEY,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `device_uuid` VARCHAR(64) NOT NULL,
  `platform` VARCHAR(16) NOT NULL,
  `app_version` VARCHAR(32) NOT NULL,
  `push_token` VARCHAR(255) NULL,
  `last_ip` VARBINARY(16) NULL,
  `last_active_at` DATETIME(3) NOT NULL,
  `created_at` DATETIME(3) NOT NULL,
  `updated_at` DATETIME(3) NOT NULL,
  UNIQUE KEY `uniq_user_device_uuid` (`user_id`, `device_uuid`),
  INDEX `idx_user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `refresh_tokens` (
  `id` BIGINT UNSIGNED NOT NULL PRIMARY KEY,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `device_id` BIGINT UNSIGNED NOT NULL,
  `token_hash` CHAR(64) NOT NULL,
  `expires_at` DATETIME(3) NOT NULL,
  `revoked_at` DATETIME(3) NULL,
  `created_at` DATETIME(3) NOT NULL,
  UNIQUE KEY `uniq_token_hash` (`token_hash`),
  INDEX `idx_user_device` (`user_id`, `device_id`),
  INDEX `idx_expires_at` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `outbox_events` (
  `id` BIGINT UNSIGNED NOT NULL PRIMARY KEY,
  `event_id` VARCHAR(36) NOT NULL,
  `aggregate_type` VARCHAR(64) NOT NULL,
  `aggregate_id` VARCHAR(64) NOT NULL,
  `event_type` VARCHAR(128) NOT NULL,
  `event_version` INT NOT NULL DEFAULT 1,
  `payload` JSON NOT NULL,
  `status` SMALLINT NOT NULL DEFAULT 0,
  `available_at` DATETIME(3) NOT NULL,
  `published_at` DATETIME(3) NULL,
  `created_at` DATETIME(3) NOT NULL,
  UNIQUE KEY `uniq_event_id` (`event_id`),
  INDEX `idx_status_available` (`status`, `available_at`),
  INDEX `idx_aggregate` (`aggregate_type`, `aggregate_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------------
-- user_db 表结构
-- -------------------------------------------------------------
USE `user_db`;

CREATE TABLE IF NOT EXISTS `users` (
  `id` BIGINT UNSIGNED NOT NULL PRIMARY KEY,
  `status` SMALLINT NOT NULL DEFAULT 1,
  `phone` VARCHAR(32) NULL,
  `email` VARCHAR(191) NULL,
  `register_channel` VARCHAR(32) NOT NULL DEFAULT 'default',
  `risk_level` SMALLINT NOT NULL DEFAULT 0,
  `last_login_at` DATETIME(3) NULL,
  `last_active_at` DATETIME(3) NULL,
  `created_at` DATETIME(3) NOT NULL,
  `updated_at` DATETIME(3) NOT NULL,
  `deleted_at` DATETIME(3) NULL,
  UNIQUE KEY `uniq_phone` (`phone`),
  UNIQUE KEY `uniq_email` (`email`),
  INDEX `idx_status` (`status`),
  INDEX `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `personas` (
  `id` BIGINT UNSIGNED NOT NULL PRIMARY KEY,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `type` SMALLINT NOT NULL DEFAULT 1,
  `is_internal_alias` TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `main_user_id` BIGINT UNSIGNED GENERATED ALWAYS AS (
    CASE WHEN `type` = 1 THEN `user_id` ELSE NULL END
  ) STORED,
  `alias_user_id` BIGINT UNSIGNED GENERATED ALWAYS AS (
    CASE WHEN `type` = 2 AND `is_internal_alias` = 1 THEN `user_id` ELSE NULL END
  ) STORED,
  `nickname` VARCHAR(64) NOT NULL,
  `avatar_key` VARCHAR(255) NULL,
  `bio` VARCHAR(500) NULL,
  `status` SMALLINT NOT NULL DEFAULT 1,
  `created_at` DATETIME(3) NOT NULL,
  `updated_at` DATETIME(3) NOT NULL,
  `deleted_at` DATETIME(3) NULL,
  CONSTRAINT `chk_persona_type` CHECK (`type` IN (1, 2)),
  CONSTRAINT `chk_internal_alias_type` CHECK (`is_internal_alias` IN (0, 1) AND (`is_internal_alias` = 0 OR `type` = 2)),
  UNIQUE KEY `uniq_main_persona_user` (`main_user_id`),
  UNIQUE KEY `uniq_internal_alias_user` (`alias_user_id`),
  INDEX `idx_user_status` (`user_id`, `status`),
  INDEX `idx_user_type_status` (`user_id`, `type`, `status`, `deleted_at`),
  INDEX `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `outbox_events` (
  `id` BIGINT UNSIGNED NOT NULL PRIMARY KEY,
  `event_id` VARCHAR(36) NOT NULL,
  `aggregate_type` VARCHAR(64) NOT NULL,
  `aggregate_id` VARCHAR(64) NOT NULL,
  `event_type` VARCHAR(128) NOT NULL,
  `event_version` INT NOT NULL DEFAULT 1,
  `payload` JSON NOT NULL,
  `status` SMALLINT NOT NULL DEFAULT 0,
  `available_at` DATETIME(3) NOT NULL,
  `published_at` DATETIME(3) NULL,
  `created_at` DATETIME(3) NOT NULL,
  UNIQUE KEY `uniq_event_id` (`event_id`),
  INDEX `idx_status_available` (`status`, `available_at`),
  INDEX `idx_aggregate` (`aggregate_type`, `aggregate_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------------
-- content_db 表结构
-- -------------------------------------------------------------
USE `content_db`;

CREATE TABLE IF NOT EXISTS `posts` (
  `id` BIGINT UNSIGNED NOT NULL PRIMARY KEY,
  `author_user_id` BIGINT UNSIGNED NULL,
  `request_id` VARCHAR(64) NULL,
  `persona_id` BIGINT UNSIGNED NOT NULL,
  `identity_mode` TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `author_display_name` VARCHAR(64) NULL,
  `author_avatar_key` VARCHAR(255) NULL,
  `author_anonymous_ref` VARCHAR(128) NULL,
  `author_scope_type` VARCHAR(255) NULL,
  `author_scope_id` VARCHAR(255) NULL,
  `type` SMALLINT NOT NULL DEFAULT 1,
  `content` TEXT NULL,
  `visibility` SMALLINT NOT NULL DEFAULT 1,
  `emotion` VARCHAR(32) NULL,
  `status` SMALLINT NOT NULL DEFAULT 1,
  `moderation_status` SMALLINT NOT NULL DEFAULT 1,
  `like_count` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `comment_count` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `view_count` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `published_at` DATETIME(3) NULL,
  `created_at` DATETIME(3) NOT NULL,
  `updated_at` DATETIME(3) NOT NULL,
  `deleted_at` DATETIME(3) NULL,
  INDEX `idx_author_user_created` (`author_user_id`, `created_at`),
  UNIQUE KEY `uniq_author_request` (`author_user_id`, `request_id`),
  INDEX `idx_alias_scope_exact` (`author_scope_type`(32), `author_scope_id`(64), `author_anonymous_ref`(128)),
  INDEX `idx_persona_created` (`persona_id`, `created_at`),
  INDEX `idx_status_published` (`status`, `published_at`),
  INDEX `idx_moderation_created` (`moderation_status`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `post_media` (
  `id` BIGINT UNSIGNED NOT NULL PRIMARY KEY,
  `post_id` BIGINT UNSIGNED NOT NULL,
  `type` SMALLINT NOT NULL DEFAULT 1,
  `object_key` VARCHAR(512) NOT NULL,
  `mime_type` VARCHAR(128) NOT NULL,
  `width` INT NULL,
  `height` INT NULL,
  `duration_ms` INT NULL,
  `sort_order` INT NOT NULL DEFAULT 0,
  `created_at` DATETIME(3) NOT NULL,
  INDEX `idx_post_sort` (`post_id`, `sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `comments` (
  `id` BIGINT UNSIGNED NOT NULL PRIMARY KEY,
  `post_id` BIGINT UNSIGNED NOT NULL,
  `author_user_id` BIGINT UNSIGNED NULL,
  `request_id` VARCHAR(64) NULL,
  `persona_id` BIGINT UNSIGNED NOT NULL,
  `identity_mode` TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `author_display_name` VARCHAR(64) NULL,
  `author_avatar_key` VARCHAR(255) NULL,
  `author_anonymous_ref` VARCHAR(128) NULL,
  `author_scope_type` VARCHAR(255) NULL,
  `author_scope_id` VARCHAR(255) NULL,
  `parent_id` BIGINT UNSIGNED NULL,
  `reply_to_comment_id` BIGINT UNSIGNED NULL,
  `content` VARCHAR(2000) NOT NULL,
  `status` SMALLINT NOT NULL DEFAULT 1,
  `like_count` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `created_at` DATETIME(3) NOT NULL,
  `updated_at` DATETIME(3) NOT NULL,
  `deleted_at` DATETIME(3) NULL,
  INDEX `idx_author_user_created` (`author_user_id`, `created_at`),
  UNIQUE KEY `uniq_author_request` (`author_user_id`, `request_id`),
  INDEX `idx_alias_scope_exact` (`author_scope_type`(32), `author_scope_id`(64), `author_anonymous_ref`(128)),
  INDEX `idx_post_created` (`post_id`, `created_at`),
  INDEX `idx_parent_created` (`parent_id`, `created_at`),
  INDEX `idx_persona_created` (`persona_id`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `outbox_events` (
  `id` BIGINT UNSIGNED NOT NULL PRIMARY KEY,
  `event_id` VARCHAR(36) NOT NULL,
  `aggregate_type` VARCHAR(64) NOT NULL,
  `aggregate_id` VARCHAR(64) NOT NULL,
  `event_type` VARCHAR(128) NOT NULL,
  `event_version` INT NOT NULL DEFAULT 1,
  `payload` JSON NOT NULL,
  `status` SMALLINT NOT NULL DEFAULT 0,
  `available_at` DATETIME(3) NOT NULL,
  `published_at` DATETIME(3) NULL,
  `created_at` DATETIME(3) NOT NULL,
  UNIQUE KEY `uniq_event_id` (`event_id`),
  INDEX `idx_status_available` (`status`, `available_at`),
  INDEX `idx_aggregate` (`aggregate_type`, `aggregate_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------------
-- social_db 表结构
-- -------------------------------------------------------------
USE `social_db`;

CREATE TABLE IF NOT EXISTS `likes` (
  `id` BIGINT UNSIGNED NOT NULL PRIMARY KEY,
  `persona_id` BIGINT UNSIGNED NOT NULL,
  `target_type` SMALLINT NOT NULL,
  `target_id` BIGINT UNSIGNED NOT NULL,
  `created_at` DATETIME(3) NOT NULL,
  CONSTRAINT `chk_like_target_type` CHECK (`target_type` IN (1, 2)),
  UNIQUE KEY `uniq_persona_target` (`persona_id`, `target_type`, `target_id`),
  INDEX `idx_target_created` (`target_type`, `target_id`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `follows` (
  `id` BIGINT UNSIGNED NOT NULL PRIMARY KEY,
  `follower_persona_id` BIGINT UNSIGNED NOT NULL,
  `followee_persona_id` BIGINT UNSIGNED NOT NULL,
  `created_at` DATETIME(3) NOT NULL,
  CONSTRAINT `chk_follow_not_self` CHECK (`follower_persona_id` <> `followee_persona_id`),
  UNIQUE KEY `uniq_follow` (`follower_persona_id`, `followee_persona_id`),
  INDEX `idx_followee_created` (`followee_persona_id`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `blocks` (
  `id` BIGINT UNSIGNED NOT NULL PRIMARY KEY,
  `blocker_user_id` BIGINT UNSIGNED NOT NULL,
  `blocked_user_id` BIGINT UNSIGNED NOT NULL,
  `created_at` DATETIME(3) NOT NULL,
  CONSTRAINT `chk_block_not_self` CHECK (`blocker_user_id` <> `blocked_user_id`),
  UNIQUE KEY `uniq_block` (`blocker_user_id`, `blocked_user_id`),
  INDEX `idx_blocked_created` (`blocked_user_id`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `outbox_events` (
  `id` BIGINT UNSIGNED NOT NULL PRIMARY KEY,
  `event_id` VARCHAR(36) NOT NULL,
  `aggregate_type` VARCHAR(64) NOT NULL,
  `aggregate_id` VARCHAR(64) NOT NULL,
  `event_type` VARCHAR(128) NOT NULL,
  `event_version` INT NOT NULL DEFAULT 1,
  `payload` JSON NOT NULL,
  `status` SMALLINT NOT NULL DEFAULT 0,
  `available_at` DATETIME(3) NOT NULL,
  `published_at` DATETIME(3) NULL,
  `created_at` DATETIME(3) NOT NULL,
  UNIQUE KEY `uniq_event_id` (`event_id`),
  INDEX `idx_status_available` (`status`, `available_at`),
  INDEX `idx_aggregate` (`aggregate_type`, `aggregate_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------------
-- chat_db 表结构
-- -------------------------------------------------------------
USE `chat_db`;

CREATE TABLE IF NOT EXISTS `conversations` (
  `id` BIGINT UNSIGNED NOT NULL PRIMARY KEY,
  `type` SMALLINT NOT NULL DEFAULT 1,
  `last_message_id` BIGINT UNSIGNED NULL,
  `last_message_at` DATETIME(3) NULL,
  `private_key` VARCHAR(64) NULL,
  `creation_request_id` VARCHAR(128) NULL,
  `requesting_user_id` BIGINT UNSIGNED NULL,
  `target_mode` TINYINT UNSIGNED NOT NULL DEFAULT 1,
  `target_user_id` BIGINT UNSIGNED NULL,
  `target_persona_id` BIGINT UNSIGNED NULL,
  `target_scope_type` VARCHAR(255) NULL,
  `target_scope_id` VARCHAR(255) NULL,
  `target_anonymous_ref` VARCHAR(128) NULL,
  `created_at` DATETIME(3) NOT NULL,
  `updated_at` DATETIME(3) NOT NULL,
  UNIQUE KEY `uniq_private_key` (`private_key`),
  INDEX `idx_creation_request` (`requesting_user_id`, `creation_request_id`),
  INDEX `idx_last_message_at` (`last_message_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `conversation_members` (
  `id` BIGINT UNSIGNED NOT NULL PRIMARY KEY,
  `conversation_id` BIGINT UNSIGNED NOT NULL,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `persona_id` BIGINT UNSIGNED NOT NULL,
  `identity_mode` TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `display_name_snapshot` VARCHAR(64) NULL,
  `avatar_key_snapshot` VARCHAR(255) NULL,
  `anonymous_ref` VARCHAR(128) NULL,
  `scope_type` VARCHAR(255) NULL,
  `scope_id` VARCHAR(255) NULL,
  `last_read_seq` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `joined_at` DATETIME(3) NOT NULL,
  `left_at` DATETIME(3) NULL,
  `mute_until` DATETIME(3) NULL,
  UNIQUE KEY `uniq_conv_user` (`conversation_id`, `user_id`),
  INDEX `idx_user_conv` (`user_id`, `conversation_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `outbox_events` (
  `id` BIGINT UNSIGNED NOT NULL PRIMARY KEY,
  `event_id` VARCHAR(36) NOT NULL,
  `aggregate_type` VARCHAR(64) NOT NULL,
  `aggregate_id` VARCHAR(64) NOT NULL,
  `event_type` VARCHAR(128) NOT NULL,
  `event_version` INT NOT NULL DEFAULT 1,
  `payload` JSON NOT NULL,
  `status` SMALLINT NOT NULL DEFAULT 0,
  `available_at` DATETIME(3) NOT NULL,
  `published_at` DATETIME(3) NULL,
  `created_at` DATETIME(3) NOT NULL,
  UNIQUE KEY `uniq_event_id` (`event_id`),
  INDEX `idx_status_available` (`status`, `available_at`),
  INDEX `idx_aggregate` (`aggregate_type`, `aggregate_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------------
-- message_db 表结构
-- -------------------------------------------------------------
USE `message_db`;

CREATE TABLE IF NOT EXISTS `messages` (
  `message_id` BIGINT UNSIGNED NOT NULL PRIMARY KEY,
  `conversation_id` BIGINT UNSIGNED NOT NULL,
  `seq` BIGINT UNSIGNED NOT NULL,
  `sender_user_id` BIGINT UNSIGNED NOT NULL,
  `sender_persona_id` BIGINT UNSIGNED NOT NULL,
  `sender_identity_mode` TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `sender_display_name` VARCHAR(64) NULL,
  `sender_avatar_key` VARCHAR(255) NULL,
  `sender_anonymous_ref` VARCHAR(128) NULL,
  `sender_scope_type` VARCHAR(255) NULL,
  `sender_scope_id` VARCHAR(255) NULL,
  `client_message_id` VARCHAR(36) NOT NULL,
  `type` SMALLINT NOT NULL DEFAULT 1,
  `content` JSON NOT NULL,
  `created_at` DATETIME(3) NOT NULL,
  UNIQUE KEY `uniq_conv_seq` (`conversation_id`, `seq`),
  UNIQUE KEY `uniq_sender_client` (`sender_user_id`, `client_message_id`),
  INDEX `idx_conv_message` (`conversation_id`, `message_id`),
  INDEX `idx_conv_seq_message` (`conversation_id`, `seq`, `message_id`),
  INDEX `idx_sender_created` (`sender_user_id`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `conversation_sequences` (
  `conversation_id` BIGINT UNSIGNED NOT NULL PRIMARY KEY,
  `last_seq` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `updated_at` DATETIME(3) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `outbox_events` (
  `id` BIGINT UNSIGNED NOT NULL PRIMARY KEY,
  `event_id` VARCHAR(36) NOT NULL,
  `aggregate_type` VARCHAR(64) NOT NULL,
  `aggregate_id` VARCHAR(64) NOT NULL,
  `event_type` VARCHAR(128) NOT NULL,
  `event_version` INT NOT NULL DEFAULT 1,
  `payload` JSON NOT NULL,
  `status` SMALLINT NOT NULL DEFAULT 0,
  `available_at` DATETIME(3) NOT NULL,
  `published_at` DATETIME(3) NULL,
  `created_at` DATETIME(3) NOT NULL,
  UNIQUE KEY `uniq_event_id` (`event_id`),
  INDEX `idx_status_available` (`status`, `available_at`),
  INDEX `idx_aggregate` (`aggregate_type`, `aggregate_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
