CREATE TABLE IF NOT EXISTS `admins` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `username` varchar(50) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `nickname` varchar(50) NOT NULL DEFAULT '',
  `avatar` varchar(255) NOT NULL DEFAULT '',
  `last_login_at` datetime DEFAULT NULL,
  `last_login_ip` varchar(45) NOT NULL DEFAULT '',
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_admin_username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `categories` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `parent_id` int unsigned NOT NULL DEFAULT '0',
  `name` varchar(100) NOT NULL,
  `description` varchar(255) NOT NULL DEFAULT '',
  `visibility` varchar(10) NOT NULL DEFAULT 'public',
  `password_hash` varchar(255) NOT NULL DEFAULT '',
  `sort` int NOT NULL DEFAULT '0',
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_category_parent` (`parent_id`),
  KEY `idx_category_visibility` (`visibility`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `documents` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `category_id` int unsigned NOT NULL DEFAULT '0',
  `title` varchar(200) NOT NULL,
  `content` mediumtext,
  `content_type` varchar(10) NOT NULL DEFAULT 'html',
  `status` tinyint(1) NOT NULL DEFAULT '0',
  `visibility` varchar(10) NOT NULL DEFAULT 'public',
  `password_hash` varchar(255) NOT NULL DEFAULT '',
  `seo_title` varchar(200) NOT NULL DEFAULT '',
  `seo_keywords` varchar(255) NOT NULL DEFAULT '',
  `seo_description` varchar(500) NOT NULL DEFAULT '',
  `baidu_push` text,
  `show_toc` tinyint(1) NOT NULL DEFAULT '1',
  `is_top` tinyint(1) NOT NULL DEFAULT '0',
  `top_at` datetime DEFAULT NULL,
  `views` int unsigned NOT NULL DEFAULT '0',
  `published_at` datetime DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  `deleted_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_doc_category` (`category_id`),
  KEY `idx_doc_status` (`status`),
  KEY `idx_doc_visibility` (`visibility`),
  KEY `idx_doc_deleted` (`deleted_at`),
  KEY `idx_doc_published` (`published_at`),
  KEY `idx_doc_top` (`is_top`, `top_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `document_versions` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `document_id` bigint unsigned NOT NULL,
  `title` varchar(200) NOT NULL,
  `content` mediumtext,
  `content_type` varchar(10) NOT NULL DEFAULT 'html',
  `version_no` int unsigned NOT NULL DEFAULT '1',
  `created_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_version_doc` (`document_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `document_drafts` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `document_id` bigint unsigned NOT NULL DEFAULT '0',
  `admin_id` int unsigned NOT NULL DEFAULT '0',
  `title` varchar(200) NOT NULL DEFAULT '',
  `category_id` int unsigned NOT NULL DEFAULT '0',
  `content` mediumtext,
  `content_type` varchar(10) NOT NULL DEFAULT 'html',
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_draft_admin_doc` (`admin_id`, `document_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `tags` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(50) NOT NULL,
  `created_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tag_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `document_tag` (
  `document_id` bigint unsigned NOT NULL,
  `tag_id` int unsigned NOT NULL,
  PRIMARY KEY (`document_id`, `tag_id`),
  KEY `idx_dt_tag` (`tag_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `attachments` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `original_name` varchar(255) NOT NULL,
  `path` varchar(255) NOT NULL,
  `size` int unsigned NOT NULL DEFAULT '0',
  `mime` varchar(100) NOT NULL DEFAULT '',
  `extension` varchar(20) NOT NULL DEFAULT '',
  `created_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_attachment_path` (`path`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `login_logs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `username` varchar(50) NOT NULL DEFAULT '',
  `ip` varchar(45) NOT NULL DEFAULT '',
  `user_agent` varchar(255) NOT NULL DEFAULT '',
  `success` tinyint(1) NOT NULL DEFAULT '0',
  `message` varchar(100) NOT NULL DEFAULT '',
  `created_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_login_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `audit_logs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `admin_id` int unsigned NOT NULL DEFAULT '0',
  `username` varchar(50) NOT NULL DEFAULT '',
  `module` varchar(30) NOT NULL DEFAULT '',
  `action` varchar(30) NOT NULL DEFAULT '',
  `description` varchar(500) NOT NULL DEFAULT '',
  `target_type` varchar(30) NOT NULL DEFAULT '',
  `target_id` bigint unsigned NOT NULL DEFAULT '0',
  `ip` varchar(45) NOT NULL DEFAULT '',
  `user_agent` varchar(255) NOT NULL DEFAULT '',
  `created_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_audit_created` (`created_at`),
  KEY `idx_audit_module` (`module`, `action`),
  KEY `idx_audit_admin` (`admin_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `settings` (
  `name` varchar(50) NOT NULL,
  `value` text,
  PRIMARY KEY (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
