-- Northmu Docs v1.7.1 升级脚本
-- 新增：后台通行密钥（Passkey / WebAuthn）登录

CREATE TABLE IF NOT EXISTS `admin_passkeys` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `admin_id` int unsigned NOT NULL DEFAULT '0',
  `credential_id` varchar(512) NOT NULL,
  `public_key` text NOT NULL,
  `alg` int NOT NULL DEFAULT '-7',
  `sign_count` int unsigned NOT NULL DEFAULT '0',
  `label` varchar(100) NOT NULL DEFAULT '',
  `last_used_at` datetime DEFAULT NULL,
  `last_used_ip` varchar(45) NOT NULL DEFAULT '',
  `created_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_passkey_credential` (`credential_id`),
  KEY `idx_passkey_admin` (`admin_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
