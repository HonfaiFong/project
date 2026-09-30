USE `auth_db`;

CREATE TABLE IF NOT EXISTS `social_identities` (
  `id` BIGINT UNSIGNED NOT NULL PRIMARY KEY,
  `user_id` BIGINT UNSIGNED NULL,
  `provider` VARCHAR(16) NOT NULL,
  `provider_app_id` VARCHAR(64) NOT NULL,
  `identity_namespace` VARCHAR(64) NOT NULL,
  `subject_type` VARCHAR(16) NOT NULL,
  `subject_id` VARCHAR(128) NOT NULL,
  `openid` VARCHAR(128) NOT NULL,
  `unionid` VARCHAR(128) NULL,
  `status` SMALLINT NOT NULL DEFAULT 0,
  `bound_at` DATETIME(3) NULL,
  `last_login_at` DATETIME(3) NULL,
  `created_at` DATETIME(3) NOT NULL,
  `updated_at` DATETIME(3) NOT NULL,
  UNIQUE KEY `uniq_social_subject` (`provider`, `identity_namespace`, `subject_type`, `subject_id`),
  UNIQUE KEY `uniq_provider_openid` (`provider`, `provider_app_id`, `openid`),
  UNIQUE KEY `uniq_user_provider` (`user_id`, `provider`),
  INDEX `idx_social_user` (`user_id`),
  INDEX `idx_social_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
