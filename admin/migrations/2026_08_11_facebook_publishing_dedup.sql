-- Facebook publishing: global deduplication + audit trail
-- See hub/lib/facebook_publisher.php for the code that reads/writes these columns.
--
-- Adds a server-side, atomic content-fingerprint dedupe gate to `social_posts`
-- (enforced via a UNIQUE index, not an application-level check-then-insert),
-- plus columns needed to log every Facebook publish attempt per the audit
-- requirements (page id, media id, redacted API response, automatic/manual, etc).
--
-- A new `facebook_duplicate_attempts` table records every publish attempt that
-- was blocked as a duplicate, referencing the original post it collided with.

ALTER TABLE `social_posts`
  ADD COLUMN `page_id` VARCHAR(64) NOT NULL DEFAULT '' AFTER `platform`,
  ADD COLUMN `event_type` VARCHAR(60) NOT NULL DEFAULT '' AFTER `post_type`,
  ADD COLUMN `content_fingerprint` CHAR(64) DEFAULT NULL AFTER `dedupe_key`,
  ADD COLUMN `image_hash` CHAR(64) DEFAULT NULL AFTER `content_fingerprint`,
  ADD COLUMN `caption_hash` CHAR(64) DEFAULT NULL AFTER `image_hash`,
  ADD COLUMN `media_id` VARCHAR(190) NOT NULL DEFAULT '' AFTER `external_post_id`,
  ADD COLUMN `api_response` MEDIUMTEXT DEFAULT NULL AFTER `error_message`,
  ADD COLUMN `is_automatic` TINYINT(1) NOT NULL DEFAULT 0 AFTER `api_response`,
  ADD UNIQUE KEY `social_posts_fingerprint_unique` (`content_fingerprint`);

CREATE TABLE IF NOT EXISTS `facebook_duplicate_attempts` (
  `id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `matched_post_id` BIGINT(20) UNSIGNED NOT NULL,
  `content_fingerprint` CHAR(64) NOT NULL,
  `post_type` VARCHAR(60) NOT NULL DEFAULT '',
  `platform` VARCHAR(30) NOT NULL DEFAULT 'facebook',
  `attempted_by` BIGINT(20) UNSIGNED DEFAULT NULL,
  `attempted_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `context` TEXT DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `facebook_duplicate_attempts_matched` (`matched_post_id`),
  KEY `facebook_duplicate_attempts_fingerprint` (`content_fingerprint`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
