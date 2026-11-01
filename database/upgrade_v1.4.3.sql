-- Northmu Docs v1.4.3 升级脚本
-- 新增：文档禁止复制开关

ALTER TABLE `documents`
  ADD COLUMN `copy_protected` tinyint(1) NOT NULL DEFAULT '0' COMMENT '禁止复制正文' AFTER `show_toc`;
