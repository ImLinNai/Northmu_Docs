-- Northmu Docs v1.6.0 升级脚本
-- 新增：公告 / 通知（前台横幅与弹窗）

CREATE TABLE IF NOT EXISTS `notices` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `title` varchar(100) NOT NULL,
  `content` text NOT NULL,
  `level` varchar(10) NOT NULL DEFAULT 'info',
  `link_url` varchar(500) NOT NULL DEFAULT '',
  `link_target` varchar(10) NOT NULL DEFAULT '_blank',
  `show_banner` tinyint(1) NOT NULL DEFAULT '1',
  `show_popup` tinyint(1) NOT NULL DEFAULT '0',
  `scroll` tinyint(1) NOT NULL DEFAULT '0',
  `popup_cooldown` int unsigned NOT NULL DEFAULT '24',
  `is_enabled` tinyint(1) NOT NULL DEFAULT '1',
  `sort` int NOT NULL DEFAULT '0',
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_notice_active` (`is_enabled`, `sort`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
