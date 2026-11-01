-- Northmu Docs v1.4.2 升级脚本
-- 新增：文档独立 SEO 字段与百度收录推送结果

ALTER TABLE `documents`
  ADD COLUMN `seo_title` varchar(200) NOT NULL DEFAULT '' COMMENT 'SEO 标题' AFTER `password_hash`,
  ADD COLUMN `seo_keywords` varchar(255) NOT NULL DEFAULT '' COMMENT 'SEO 关键词' AFTER `seo_title`,
  ADD COLUMN `seo_description` varchar(500) NOT NULL DEFAULT '' COMMENT 'SEO 描述' AFTER `seo_keywords`,
  ADD COLUMN `baidu_push` text COMMENT '百度收录推送结果 JSON' AFTER `seo_description`;
