-- AVSF clean schema-only installer. Select the new database before importing.
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `settings` (
  `skey` VARCHAR(50) NOT NULL,
  `svalue` TEXT DEFAULT NULL,
  PRIMARY KEY (`skey`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `studios` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(100) NOT NULL,
  `slug` VARCHAR(150) NOT NULL,
  `logo` VARCHAR(255) DEFAULT '',
  `desc` TEXT DEFAULT NULL,
  `metaTitle` VARCHAR(200) DEFAULT '',
  `metaDesc` VARCHAR(300) DEFAULT '',
  PRIMARY KEY (`id`),
  UNIQUE KEY `slug` (`slug`),
  KEY `idx_slug_studios` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `actors` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(100) NOT NULL,
  `slug` VARCHAR(150) NOT NULL,
  `photo` VARCHAR(255) DEFAULT '',
  `bio` TEXT DEFAULT NULL,
  `metaTitle` VARCHAR(200) DEFAULT '',
  `metaDesc` VARCHAR(300) DEFAULT '',
  PRIMARY KEY (`id`),
  UNIQUE KEY `slug` (`slug`),
  KEY `idx_slug_actors` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `tags` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(100) NOT NULL,
  `slug` VARCHAR(150) NOT NULL,
  `metaTitle` VARCHAR(200) DEFAULT '',
  `metaDesc` VARCHAR(300) DEFAULT '',
  PRIMARY KEY (`id`),
  UNIQUE KEY `slug` (`slug`),
  KEY `idx_slug_tags` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `categories` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(150) NOT NULL,
  `slug` VARCHAR(150) NOT NULL,
  `metaTitle` VARCHAR(200) DEFAULT '',
  `metaDesc` VARCHAR(300) DEFAULT '',
  PRIMARY KEY (`id`),
  UNIQUE KEY `slug` (`slug`),
  KEY `idx_slug_categories` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `slug_aliases` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `entityType` VARCHAR(20) NOT NULL,
  `oldSlug` VARCHAR(200) NOT NULL,
  `newSlug` VARCHAR(200) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `entity_old_slug` (`entityType`,`oldSlug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `seo_index_overrides` (
  `entity_type` VARCHAR(30) NOT NULL,
  `entity_id` INT UNSIGNED NOT NULL,
  `indexable` TINYINT(1) NOT NULL DEFAULT 1,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`entity_type`,`entity_id`),
  KEY `idx_indexable_type` (`entity_type`,`indexable`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `posts` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `code` VARCHAR(100) DEFAULT '',
  `title` VARCHAR(200) NOT NULL,
  `slug` VARCHAR(200) NOT NULL,
  `content` TEXT DEFAULT NULL,
  `cover` VARCHAR(255) DEFAULT '',
  `posterUrl` VARCHAR(1000) DEFAULT '',
  `coverAlt` VARCHAR(255) DEFAULT '',
  `posterAlt` VARCHAR(255) DEFAULT '',
  `galleryAlt` TEXT DEFAULT NULL,
  `videoUrl` TEXT DEFAULT NULL,
  `embedUrl` VARCHAR(1000) DEFAULT '',
  `videoDuration` VARCHAR(20) DEFAULT '',
  `gallery` TEXT DEFAULT NULL,
  `studioId` INT DEFAULT 0,
  `actorIds` TEXT DEFAULT NULL,
  `actorEN` VARCHAR(150) DEFAULT '',
  `tagIds` TEXT DEFAULT NULL,
  `category` VARCHAR(150) DEFAULT '',
  `focusKeyphrase` VARCHAR(200) DEFAULT '',
  `metaTitle` VARCHAR(200) DEFAULT '',
  `metaDesc` VARCHAR(300) DEFAULT '',
  `keywords` VARCHAR(300) DEFAULT '',
  `status` VARCHAR(20) DEFAULT 'draft',
  `date` DATE DEFAULT NULL,
  `author` VARCHAR(100) DEFAULT '',
  `createdAt` DATETIME DEFAULT NULL,
  `updatedAt` DATETIME DEFAULT NULL,
  `views` INT DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `slug` (`slug`),
  KEY `idx_status_date` (`status`, `date`),
  KEY `idx_studio` (`studioId`),
  KEY `idx_created` (`createdAt`),
  KEY `idx_code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `banners` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `title` VARCHAR(150) NOT NULL,
  `link` VARCHAR(255) DEFAULT '',
  `image` VARCHAR(255) DEFAULT '',
  `text` VARCHAR(255) DEFAULT '',
  `active` TINYINT(1) DEFAULT 1,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `pages` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `title` VARCHAR(150) NOT NULL,
  `slug` VARCHAR(150) NOT NULL,
  `content` TEXT DEFAULT NULL,
  `status` VARCHAR(20) DEFAULT 'publish',
  `date` DATE DEFAULT NULL,
  `metaTitle` VARCHAR(200) NOT NULL DEFAULT '',
  `metaDesc` VARCHAR(300) NOT NULL DEFAULT '',
  PRIMARY KEY (`id`),
  UNIQUE KEY `slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `comments` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `postId` INT NOT NULL,
  `name` VARCHAR(100) DEFAULT 'ไม่ระบุชื่อ',
  `text` TEXT NOT NULL,
  `date` DATE DEFAULT NULL,
  `approved` TINYINT(1) DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_post_approved` (`postId`, `approved`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `users` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `username` VARCHAR(50) NOT NULL,
  `password` VARCHAR(255) NOT NULL,
  `email` VARCHAR(150) DEFAULT NULL,
  `phone` VARCHAR(30) DEFAULT '',
  `role` VARCHAR(50) DEFAULT 'custom',
  `permissions` TEXT DEFAULT NULL,
  `twofa_secret` VARCHAR(64) DEFAULT '',
  `twofa_enabled` TINYINT(1) DEFAULT 0,
  `reset_token` VARCHAR(64) DEFAULT '',
  `reset_expires` DATETIME DEFAULT NULL,
  `lastLogin` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`),
  UNIQUE KEY `email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `activity` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `action` VARCHAR(255) NOT NULL,
  `time` DATETIME DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_time` (`time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `login_attempts` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `ip` VARCHAR(45) NOT NULL,
  `username` VARCHAR(100) DEFAULT '',
  `success` TINYINT(1) DEFAULT 0,
  `attempted_at` DATETIME NOT NULL,
  INDEX `idx_ip` (`ip`),
  INDEX `idx_time` (`attempted_at`),
  INDEX `idx_ip_login` (`ip`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `redirects` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `old_url` VARCHAR(500) NOT NULL,
  `new_url` VARCHAR(500) NOT NULL DEFAULT '',
  `code` INT DEFAULT 301,
  `hits` INT DEFAULT 0,
  `note` VARCHAR(255) DEFAULT '',
  `active` TINYINT(1) DEFAULT 1,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `idx_old_url` (`old_url`(191))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `cache_stats` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `stat_key` VARCHAR(50) NOT NULL,
  `stat_value` BIGINT DEFAULT 0,
  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `idx_key` (`stat_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `404_log` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `path` VARCHAR(500) NOT NULL,
  `hits` INT DEFAULT 1,
  `first_hit` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `last_hit` DATETIME DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `idx_path` (`path`(191))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `server_error_log` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `path` VARCHAR(500) NOT NULL,
  `error_type` VARCHAR(40) NOT NULL,
  `hits` INT NOT NULL DEFAULT 1,
  `first_hit` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `last_hit` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_path_type` (`path`(191),`error_type`),
  KEY `idx_last_hit` (`last_hit`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `recent_page_views` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `page_path` VARCHAR(500) NOT NULL,
  `page_title` VARCHAR(255) NOT NULL DEFAULT '',
  `viewed_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_recent_page_views_time` (`viewed_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- Persistent observability histories. No raw filenames, credentials, IPs or source paths are stored.
CREATE TABLE IF NOT EXISTS `import_history` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `job_token_hash` CHAR(64) NOT NULL,
  `mode` VARCHAR(24) NOT NULL DEFAULT 'posts',
  `total_rows` INT UNSIGNED NOT NULL DEFAULT 0,
  `inserted_rows` INT UNSIGNED NOT NULL DEFAULT 0,
  `updated_rows` INT UNSIGNED NOT NULL DEFAULT 0,
  `skipped_rows` INT UNSIGNED NOT NULL DEFAULT 0,
  `failed_rows` INT UNSIGNED NOT NULL DEFAULT 0,
  `saved_images` INT UNSIGNED NOT NULL DEFAULT 0,
  `linked_images` INT UNSIGNED NOT NULL DEFAULT 0,
  `started_at` DATETIME NOT NULL,
  `finished_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `created_by` INT UNSIGNED NOT NULL DEFAULT 0,
  `summary_text` VARCHAR(240) NOT NULL DEFAULT '',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_import_job_token_hash` (`job_token_hash`),
  KEY `idx_import_history_finished` (`finished_at`),
  KEY `idx_import_history_mode` (`mode`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `security_scan_history` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `scan_type` VARCHAR(24) NOT NULL,
  `scanned_files` INT UNSIGNED DEFAULT NULL,
  `infected_files` INT UNSIGNED NOT NULL DEFAULT 0,
  `error_count` INT UNSIGNED NOT NULL DEFAULT 0,
  `limit_reached` TINYINT(1) NOT NULL DEFAULT 0,
  `result_status` VARCHAR(16) NOT NULL DEFAULT 'info',
  `started_at` DATETIME NOT NULL,
  `finished_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `duration_ms` INT UNSIGNED NOT NULL DEFAULT 0,
  `created_by` INT UNSIGNED NOT NULL DEFAULT 0,
  `summary_text` VARCHAR(240) NOT NULL DEFAULT '',
  PRIMARY KEY (`id`),
  KEY `idx_security_scan_finished` (`finished_at`),
  KEY `idx_security_scan_type` (`scan_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
