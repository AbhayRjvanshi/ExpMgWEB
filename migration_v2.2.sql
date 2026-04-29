-- ============================================================
-- Migration v2.2 — Rate Limiting
-- Adds rate_limits table for IP-based rate limiting.
-- Run once on existing databases:
--   mysql -u root expmgweb < migration_v2.2.sql
-- ============================================================

USE expmgweb;

CREATE TABLE IF NOT EXISTS rate_limits (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    ip_address   VARCHAR(45)  NOT NULL,
    action       VARCHAR(30)  NOT NULL,
    attempted_at TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_rate_ip_action (ip_address, action, attempted_at)
) ENGINE=InnoDB;
