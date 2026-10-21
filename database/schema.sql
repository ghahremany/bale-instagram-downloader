-- ============================================================================
--  Bale Instagram Downloader  |  MySQL / MariaDB schema
--  charset: utf8mb4  |  engine: InnoDB
--  All DATETIME values are stored in UTC.
-- ============================================================================

CREATE TABLE IF NOT EXISTS `settings` (
  `key`        VARCHAR(64)  NOT NULL,
  `value`      MEDIUMTEXT   NULL,
  `updated_at` DATETIME     NOT NULL,
  PRIMARY KEY (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `admins` (
  `id`                   INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `username`             VARCHAR(64)  NOT NULL,
  `password_hash`        VARCHAR(255) NULL,
  `display_name`         VARCHAR(120) NULL,
  `role`                 ENUM('owner','admin') NOT NULL DEFAULT 'admin',
  `is_active`            TINYINT(1)   NOT NULL DEFAULT 1,
  `must_change_password` TINYINT(1)   NOT NULL DEFAULT 0,
  `failed_attempts`      SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `locked_until`         DATETIME     NULL,
  `last_login_at`        DATETIME     NULL,
  `last_login_ip`        VARCHAR(45)  NULL,
  `created_at`           DATETIME     NOT NULL,
  `updated_at`           DATETIME     NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_admins_username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `users` (
  `id`               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `platform`         VARCHAR(16)  NOT NULL DEFAULT 'bale',
  `platform_user_id` BIGINT       NOT NULL,
  `chat_id`          BIGINT       NULL,
  `username`         VARCHAR(64)  NULL,
  `first_name`       VARCHAR(128) NULL,
  `last_name`        VARCHAR(128) NULL,
  `is_blocked`       TINYINT(1)   NOT NULL DEFAULT 0,
  `is_joined`        TINYINT(1)   NOT NULL DEFAULT 0,
  `downloads_count`  INT UNSIGNED NOT NULL DEFAULT 0,
  `created_at`       DATETIME     NOT NULL,
  `last_seen_at`     DATETIME     NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_users_platform` (`platform`, `platform_user_id`),
  KEY `idx_users_created` (`created_at`),
  KEY `idx_users_blocked` (`is_blocked`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `channels` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `title`      VARCHAR(120) NOT NULL,
  `chat_id`    VARCHAR(64)  NOT NULL,
  `link`       VARCHAR(255) NOT NULL,
  `is_active`  TINYINT(1)   NOT NULL DEFAULT 1,
  `sort_order` INT          NOT NULL DEFAULT 0,
  `created_at` DATETIME     NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_channels_active` (`is_active`, `sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `downloads` (
  `id`               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`          BIGINT UNSIGNED NULL,
  `platform_user_id` BIGINT       NULL,
  `url`              TEXT         NOT NULL,
  `shortcode`        VARCHAR(64)  NULL,
  `media_type`       VARCHAR(24)  NULL,
  `status`           ENUM('success','partial','fallback','failed') NOT NULL DEFAULT 'failed',
  `files_total`      SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `files_sent`       SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `bytes`            BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `duration_ms`      INT UNSIGNED NOT NULL DEFAULT 0,
  `error_message`    VARCHAR(500) NULL,
  `created_at`       DATETIME     NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_downloads_created` (`created_at`),
  KEY `idx_downloads_status` (`status`),
  KEY `idx_downloads_user` (`platform_user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `broadcasts` (
  `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `admin_id`       INT UNSIGNED NULL,
  `body`           TEXT         NULL,
  `media_type`     VARCHAR(16)  NULL,
  `media_file_id`  VARCHAR(255) NULL,
  `button_text`    VARCHAR(64)  NULL,
  `button_url`     VARCHAR(500) NULL,
  `status`         ENUM('queued','running','done','canceled','failed') NOT NULL DEFAULT 'queued',
  `total`          INT UNSIGNED NOT NULL DEFAULT 0,
  `sent`           INT UNSIGNED NOT NULL DEFAULT 0,
  `failed`         INT UNSIGNED NOT NULL DEFAULT 0,
  `created_at`     DATETIME     NOT NULL,
  `started_at`     DATETIME     NULL,
  `finished_at`    DATETIME     NULL,
  PRIMARY KEY (`id`),
  KEY `idx_broadcasts_status` (`status`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `broadcast_targets` (
  `id`               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `broadcast_id`     INT UNSIGNED NOT NULL,
  `platform_user_id` BIGINT       NOT NULL,
  `status`           ENUM('pending','sent','failed','skipped') NOT NULL DEFAULT 'pending',
  `error_message`    VARCHAR(300) NULL,
  `sent_at`          DATETIME     NULL,
  PRIMARY KEY (`id`),
  KEY `idx_targets_job` (`broadcast_id`, `status`),
  CONSTRAINT `fk_targets_broadcast` FOREIGN KEY (`broadcast_id`) REFERENCES `broadcasts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `login_attempts` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `username`   VARCHAR(64)  NULL,
  `ip`         VARCHAR(45)  NULL,
  `success`    TINYINT(1)   NOT NULL DEFAULT 0,
  `created_at` DATETIME     NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_login_ip` (`ip`, `created_at`),
  KEY `idx_login_user` (`username`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `rate_limits` (
  `bucket`     VARCHAR(120) NOT NULL,
  `hits`       INT UNSIGNED NOT NULL DEFAULT 0,
  `expires_at` INT UNSIGNED NOT NULL,
  PRIMARY KEY (`bucket`),
  KEY `idx_rate_expires` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `setup_tokens` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `admin_id`   INT UNSIGNED NULL,
  `token_hash` VARCHAR(255) NOT NULL,
  `purpose`    VARCHAR(32)  NOT NULL DEFAULT 'first_login',
  `expires_at` DATETIME     NOT NULL,
  `used_at`    DATETIME     NULL,
  `created_at` DATETIME     NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_setup_purpose` (`purpose`, `expires_at`),
  KEY `idx_setup_admin` (`admin_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `audit_log` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `admin_id`   INT UNSIGNED NULL,
  `action`     VARCHAR(64)  NOT NULL,
  `details`    VARCHAR(500) NULL,
  `ip`         VARCHAR(45)  NULL,
  `created_at` DATETIME     NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_audit_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `bot_state` (
  `id`             TINYINT UNSIGNED NOT NULL DEFAULT 1,
  `last_update_id` BIGINT NOT NULL DEFAULT 0,
  `updated_at`     DATETIME NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `processed_updates` (
  `update_id`  BIGINT UNSIGNED NOT NULL,
  `created_at` DATETIME NOT NULL,
  PRIMARY KEY (`update_id`),
  KEY `idx_processed_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
