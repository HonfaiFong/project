-- Scope device identity to the owning user. Before this migration the
-- repository could move one device row between users when they supplied the
-- same device UUID. Refresh tokens left behind with the old owner cannot be
-- safely attributed to a device row, so revoke those inconsistent sessions
-- before changing the unique key.
USE `auth_db`;

UPDATE `refresh_tokens` AS rt
LEFT JOIN `devices` AS d ON d.`id` = rt.`device_id`
SET rt.`revoked_at` = COALESCE(rt.`revoked_at`, NOW(3))
WHERE rt.`revoked_at` IS NULL
  AND (d.`id` IS NULL OR d.`user_id` <> rt.`user_id`);

SET @has_user_device_index := (
  SELECT COUNT(*)
  FROM information_schema.statistics
  WHERE table_schema = DATABASE()
    AND table_name = 'devices'
    AND index_name = 'uniq_user_device_uuid'
);
SET @add_user_device_index := IF(
  @has_user_device_index = 0,
  'ALTER TABLE `devices` ADD UNIQUE KEY `uniq_user_device_uuid` (`user_id`, `device_uuid`)',
  'SELECT 1'
);
PREPARE add_user_device_index FROM @add_user_device_index;
EXECUTE add_user_device_index;
DEALLOCATE PREPARE add_user_device_index;

SET @has_legacy_device_index := (
  SELECT COUNT(*)
  FROM information_schema.statistics
  WHERE table_schema = DATABASE()
    AND table_name = 'devices'
    AND index_name = 'uniq_device_uuid'
);
SET @drop_legacy_device_index := IF(
  @has_legacy_device_index > 0,
  'ALTER TABLE `devices` DROP INDEX `uniq_device_uuid`',
  'SELECT 1'
);
PREPARE drop_legacy_device_index FROM @drop_legacy_device_index;
EXECUTE drop_legacy_device_index;
DEALLOCATE PREPARE drop_legacy_device_index;
