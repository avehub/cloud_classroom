-- ============================================================================
-- 生产环境发布 SQL：永久会员功能（兑换码 + 999元永久套餐）
-- 对应迁移：V20261021000001、V20261022000001
-- 适用版本：MySQL 5.7
-- 执行方式：mysql -u<user> -p <database> < patch_20261022_permanent_vip.sql
-- 注意：1~4 步为结构性变更，仅可执行一次（重复执行会报"已存在"错误，属预期）；
--       第 5 步为幂等插入，可安全重复执行。
-- ============================================================================

-- ----------------------------------------------------------------------------
-- 1. kg_user 新增永久会员标识字段
-- ----------------------------------------------------------------------------
ALTER TABLE `kg_user`
    ADD COLUMN `vip_permanent` tinyint(4) unsigned NOT NULL DEFAULT '0' COMMENT '永久会员标识' AFTER `vip_expiry_time`;

-- ----------------------------------------------------------------------------
-- 2. 新建会员兑换码批次表
-- ----------------------------------------------------------------------------
CREATE TABLE `kg_vip_redeem_batch` (
    `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
    `name` varchar(50) NOT NULL DEFAULT '' COMMENT '批次名称',
    `code_count` int(11) unsigned NOT NULL DEFAULT '0' COMMENT '兑换码数量',
    `expire_time` int(11) unsigned NOT NULL DEFAULT '0' COMMENT '兑换截止时间',
    `status` tinyint(4) unsigned NOT NULL DEFAULT '1' COMMENT '状态：0停用 1启用',
    `remark` varchar(255) NOT NULL DEFAULT '' COMMENT '备注',
    `creator_id` int(11) unsigned NOT NULL DEFAULT '0' COMMENT '创建管理员编号',
    `creator_name` varchar(50) NOT NULL DEFAULT '' COMMENT '创建管理员名称',
    `create_time` int(11) unsigned NOT NULL DEFAULT '0',
    `update_time` int(11) unsigned NOT NULL DEFAULT '0',
    PRIMARY KEY (`id`),
    KEY `status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='会员兑换码批次';

-- ----------------------------------------------------------------------------
-- 3. 新建永久会员兑换码表
-- ----------------------------------------------------------------------------
CREATE TABLE `kg_vip_redeem_code` (
    `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
    `batch_id` int(11) unsigned NOT NULL DEFAULT '0' COMMENT '批次编号',
    `code` varchar(255) NOT NULL DEFAULT '' COMMENT '加密兑换码',
    `code_hash` varchar(64) NOT NULL DEFAULT '' COMMENT '兑换码哈希',
    `status` tinyint(4) unsigned NOT NULL DEFAULT '0' COMMENT '状态：0未使用 1已使用 2已停用',
    `user_id` int(11) unsigned NOT NULL DEFAULT '0' COMMENT '兑换用户编号',
    `user_name` varchar(50) NOT NULL DEFAULT '' COMMENT '兑换用户名称',
    `redeem_time` int(11) unsigned NOT NULL DEFAULT '0' COMMENT '兑换时间',
    `client_ip` varchar(45) NOT NULL DEFAULT '' COMMENT '兑换IP',
    `create_time` int(11) unsigned NOT NULL DEFAULT '0',
    `update_time` int(11) unsigned NOT NULL DEFAULT '0',
    PRIMARY KEY (`id`),
    UNIQUE KEY `code_hash` (`code_hash`),
    KEY `batch_id` (`batch_id`),
    KEY `status` (`status`),
    KEY `user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='永久会员兑换码';

-- ----------------------------------------------------------------------------
-- 4. kg_vip.expiry 字段注释更新（0 表示永久套餐，仅改注释，不影响数据）
-- ----------------------------------------------------------------------------
ALTER TABLE `kg_vip`
    MODIFY COLUMN `expiry` int(11) unsigned NOT NULL DEFAULT '0' COMMENT '有效期（月），0表示永久';

-- ----------------------------------------------------------------------------
-- 5. 插入 999 元永久会员套餐（幂等：已存在 expiry=0 的套餐时跳过）
-- ----------------------------------------------------------------------------
INSERT INTO `kg_vip` (`title`, `cover`, `expiry`, `price`, `published`, `deleted`, `create_time`, `update_time`)
SELECT '永久会员', '', 0, 999.00, 1, 0, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `kg_vip` WHERE `expiry` = 0);

-- ============================================================================
-- 发布后验证（可选）
-- ----------------------------------------------------------------------------
-- SELECT COUNT(*) AS permanent_col FROM information_schema.COLUMNS
--  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'kg_user' AND COLUMN_NAME = 'vip_permanent';
-- SHOW TABLES LIKE 'kg_vip_redeem_%';
-- SELECT id, title, expiry, price, published FROM kg_vip WHERE expiry = 0;

-- ============================================================================
-- 回滚 SQL（仅在需要回退时手动执行）
-- ----------------------------------------------------------------------------
-- DELETE FROM `kg_vip` WHERE `expiry` = 0;
-- ALTER TABLE `kg_vip` MODIFY COLUMN `expiry` int(11) unsigned NOT NULL DEFAULT '0' COMMENT '有效期';
-- DROP TABLE IF EXISTS `kg_vip_redeem_code`;
-- DROP TABLE IF EXISTS `kg_vip_redeem_batch`;
-- ALTER TABLE `kg_user` DROP COLUMN `vip_permanent`;
