-- -------------------------------------------------------------
-- Initialize logical databases for Social Backend
-- -------------------------------------------------------------
CREATE DATABASE IF NOT EXISTS `auth_db` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE IF NOT EXISTS `user_db` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE IF NOT EXISTS `content_db` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE IF NOT EXISTS `social_db` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE IF NOT EXISTS `chat_db` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE IF NOT EXISTS `message_db` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- Grant privileges
GRANT ALL PRIVILEGES ON *.* TO 'root'@'%' WITH GRANT OPTION;
FLUSH PRIVILEGES;

-- -------------------------------------------------------------
-- auth_db Schema
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
-- user_db Schema
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
  `main_user_id` BIGINT UNSIGNED GENERATED ALWAYS AS (
    CASE WHEN `type` = 1 THEN `user_id` ELSE NULL END
  ) STORED,
  `nickname` VARCHAR(64) NOT NULL,
  `avatar_key` VARCHAR(255) NULL,
  `bio` VARCHAR(500) NULL,
  `status` SMALLINT NOT NULL DEFAULT 1,
  `created_at` DATETIME(3) NOT NULL,
  `updated_at` DATETIME(3) NOT NULL,
  `deleted_at` DATETIME(3) NULL,
  CONSTRAINT `chk_persona_type` CHECK (`type` IN (1, 2)),
  UNIQUE KEY `uniq_main_persona_user` (`main_user_id`),
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
-- content_db Schema
-- -------------------------------------------------------------
USE `content_db`;

CREATE TABLE IF NOT EXISTS `posts` (
  `id` BIGINT UNSIGNED NOT NULL PRIMARY KEY,
  `persona_id` BIGINT UNSIGNED NOT NULL,
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
  `persona_id` BIGINT UNSIGNED NOT NULL,
  `parent_id` BIGINT UNSIGNED NULL,
  `reply_to_comment_id` BIGINT UNSIGNED NULL,
  `content` VARCHAR(2000) NOT NULL,
  `status` SMALLINT NOT NULL DEFAULT 1,
  `like_count` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `created_at` DATETIME(3) NOT NULL,
  `updated_at` DATETIME(3) NOT NULL,
  `deleted_at` DATETIME(3) NULL,
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
-- social_db Schema
-- -------------------------------------------------------------
USE `social_db`;

CREATE TABLE IF NOT EXISTS `likes` (
  `id` BIGINT UNSIGNED NOT NULL PRIMARY KEY,
  `persona_id` BIGINT UNSIGNED NOT NULL,
  `target_type` SMALLINT NOT NULL,
  `target_id` BIGINT UNSIGNED NOT NULL,
  `created_at` DATETIME(3) NOT NULL,
  UNIQUE KEY `uniq_persona_target` (`persona_id`, `target_type`, `target_id`),
  INDEX `idx_target_created` (`target_type`, `target_id`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `follows` (
  `id` BIGINT UNSIGNED NOT NULL PRIMARY KEY,
  `follower_persona_id` BIGINT UNSIGNED NOT NULL,
  `followee_persona_id` BIGINT UNSIGNED NOT NULL,
  `created_at` DATETIME(3) NOT NULL,
  UNIQUE KEY `uniq_follow` (`follower_persona_id`, `followee_persona_id`),
  INDEX `idx_followee_created` (`followee_persona_id`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `blocks` (
  `id` BIGINT UNSIGNED NOT NULL PRIMARY KEY,
  `blocker_user_id` BIGINT UNSIGNED NOT NULL,
  `blocked_user_id` BIGINT UNSIGNED NOT NULL,
  `created_at` DATETIME(3) NOT NULL,
  UNIQUE KEY `uniq_block` (`blocker_user_id`, `blocked_user_id`)
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
-- chat_db Schema
-- -------------------------------------------------------------
USE `chat_db`;

CREATE TABLE IF NOT EXISTS `conversations` (
  `id` BIGINT UNSIGNED NOT NULL PRIMARY KEY,
  `type` SMALLINT NOT NULL DEFAULT 1,
  `last_message_id` BIGINT UNSIGNED NULL,
  `last_message_at` DATETIME(3) NULL,
  `created_at` DATETIME(3) NOT NULL,
  `updated_at` DATETIME(3) NOT NULL,
  INDEX `idx_last_message_at` (`last_message_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `conversation_members` (
  `id` BIGINT UNSIGNED NOT NULL PRIMARY KEY,
  `conversation_id` BIGINT UNSIGNED NOT NULL,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `persona_id` BIGINT UNSIGNED NOT NULL,
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
-- message_db Schema
-- -------------------------------------------------------------
USE `message_db`;

CREATE TABLE IF NOT EXISTS `messages` (
  `message_id` BIGINT UNSIGNED NOT NULL PRIMARY KEY,
  `conversation_id` BIGINT UNSIGNED NOT NULL,
  `seq` BIGINT UNSIGNED NOT NULL,
  `sender_user_id` BIGINT UNSIGNED NOT NULL,
  `sender_persona_id` BIGINT UNSIGNED NOT NULL,
  `client_message_id` VARCHAR(36) NOT NULL,
  `type` SMALLINT NOT NULL DEFAULT 1,
  `content` JSON NOT NULL,
  `created_at` DATETIME(3) NOT NULL,
  UNIQUE KEY `uniq_conv_seq` (`conversation_id`, `seq`),
  UNIQUE KEY `uniq_sender_client` (`sender_user_id`, `client_message_id`),
  INDEX `idx_conv_message` (`conversation_id`, `message_id`),
  INDEX `idx_sender_created` (`sender_user_id`, `created_at`)
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
