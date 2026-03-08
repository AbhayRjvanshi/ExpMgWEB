-- ============================================================
-- Expense Manager — Database Schema
-- Run this file in phpMyAdmin or the MySQL CLI:
--   mysql -u root -p < schema.sql
-- ============================================================

-- Create the database (idempotent)
CREATE DATABASE IF NOT EXISTS ExpMgWEB
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_general_ci;

USE ExpMgWEB;

-- ============================================================
-- 1. users
-- ============================================================
CREATE TABLE IF NOT EXISTS users (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    username    VARCHAR(50)  NOT NULL UNIQUE,
    email       VARCHAR(100) NOT NULL UNIQUE,
    password    VARCHAR(255) NOT NULL,          -- bcrypt hash
    created_at  TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    updated_at  TIMESTAMP    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ============================================================
-- 2. categories  (pre-seeded; referenced by expenses & list items)
-- ============================================================
CREATE TABLE IF NOT EXISTS categories (
    id   INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(50) NOT NULL UNIQUE
) ENGINE=InnoDB;

-- Seed the default categories
INSERT IGNORE INTO categories (name) VALUES
    ('Food/Groceries'),
    ('Transport'),
    ('Utilities'),
    ('Bills'),
    ('Shopping'),
    ('Education'),
    ('Health'),
    ('Others');

-- ============================================================
-- 3. groups
--    • A user can create at most 5 groups (enforced in PHP).
--    • A group can have at most 10 members (enforced in PHP + max_members).
-- ============================================================
CREATE TABLE IF NOT EXISTS `groups` (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name        VARCHAR(100) NOT NULL,
    join_code   VARCHAR(20)  NOT NULL UNIQUE,   -- shareable code
    created_by  INT UNSIGNED NOT NULL,           -- admin / creator
    max_members TINYINT UNSIGNED DEFAULT 10,
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ============================================================
-- 4. group_members  (many-to-many: users ↔ groups)
-- ============================================================
CREATE TABLE IF NOT EXISTS group_members (
    id        INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    group_id  INT UNSIGNED NOT NULL,
    user_id   INT UNSIGNED NOT NULL,
    role      ENUM('admin','member') DEFAULT 'member',
    joined_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    UNIQUE KEY uq_group_user (group_id, user_id),
    FOREIGN KEY (group_id) REFERENCES `groups`(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id)  REFERENCES users(id)    ON DELETE CASCADE
) ENGINE=InnoDB;

-- ============================================================
-- 5. expenses
--    • type = 'personal' → group_id IS NULL
--    • type = 'group'    → group_id IS NOT NULL
--    Personal expenses: owner can update/delete.
--    Group expenses:    only group admin can update/delete.
-- ============================================================
CREATE TABLE IF NOT EXISTS expenses (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id      INT UNSIGNED NOT NULL,               -- who recorded it
    group_id     INT UNSIGNED DEFAULT NULL,            -- NULL = personal
    amount       DECIMAL(12,2) NOT NULL,
    category_id  INT UNSIGNED  NOT NULL,
    note         VARCHAR(255)  DEFAULT NULL,
    expense_date DATE          NOT NULL,               -- calendar date
    type         ENUM('personal','group') NOT NULL DEFAULT 'personal',
    created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    INDEX idx_user_date   (user_id, expense_date),
    INDEX idx_group_date  (group_id, expense_date),

    FOREIGN KEY (user_id)     REFERENCES users(id)      ON DELETE CASCADE,
    FOREIGN KEY (group_id)    REFERENCES `groups`(id)    ON DELETE SET NULL,
    FOREIGN KEY (category_id) REFERENCES categories(id)  ON DELETE RESTRICT
) ENGINE=InnoDB;

-- ============================================================
-- 6. budgets  (monthly spending limit per user)
-- ============================================================
CREATE TABLE IF NOT EXISTS budgets (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id      INT UNSIGNED NOT NULL,
    budget_month CHAR(7)      NOT NULL,    -- 'YYYY-MM'
    amount_limit DECIMAL(12,2) NOT NULL,
    created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY uq_user_month (user_id, budget_month),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ============================================================
-- 7. lists  (personal or group shopping / to-buy lists)
-- ============================================================
CREATE TABLE IF NOT EXISTS lists (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name       VARCHAR(100) NOT NULL,
    user_id    INT UNSIGNED NOT NULL,          -- creator
    group_id   INT UNSIGNED DEFAULT NULL,      -- NULL = personal list
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    FOREIGN KEY (user_id)  REFERENCES users(id)    ON DELETE CASCADE,
    FOREIGN KEY (group_id) REFERENCES `groups`(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ============================================================
-- 8. list_items
--    Priority ordering: high → moderate → low
--    Within the same priority, newest items appear last.
-- ============================================================
CREATE TABLE IF NOT EXISTS list_items (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    list_id     INT UNSIGNED NOT NULL,
    description VARCHAR(255) NOT NULL,
    category_id INT UNSIGNED DEFAULT NULL,
    priority    ENUM('high','moderate','low') NOT NULL DEFAULT 'low',
    is_checked  TINYINT(1)   DEFAULT 0,
    added_by    INT UNSIGNED NOT NULL,
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_list_priority (list_id, priority, created_at),

    FOREIGN KEY (list_id)     REFERENCES lists(id)       ON DELETE CASCADE,
    FOREIGN KEY (category_id) REFERENCES categories(id)  ON DELETE SET NULL,
    FOREIGN KEY (added_by)    REFERENCES users(id)       ON DELETE CASCADE
) ENGINE=InnoDB;

-- ============================================================
-- 9. notifications
--    type examples: 'group_join', 'group_leave', 'group_delete',
--                   'group_expense_add', 'group_expense_update',
--                   'group_expense_delete', 'settlement',
--                   'list_item_add', 'list_item_remove', 'list_item_check'
--    Daily lifecycle: only today's notifications are displayed;
--    older ones are auto-cleaned on list fetch.
-- ============================================================
CREATE TABLE IF NOT EXISTS notifications (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id      INT UNSIGNED NOT NULL,            -- recipient
    message      VARCHAR(500) NOT NULL,
    type         VARCHAR(50)  NOT NULL,
    reference_id INT UNSIGNED DEFAULT NULL,         -- e.g. expense_id, list_id, group_id
    is_read      TINYINT(1)   DEFAULT 0,
    created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_user_read (user_id, is_read, created_at),

    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ============================================================
-- 10. settlements (records of completed group settlements)
-- ============================================================
CREATE TABLE IF NOT EXISTS settlements (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    group_id      INT UNSIGNED NOT NULL,
    settled_by    INT UNSIGNED NOT NULL,
    payer_id      INT UNSIGNED NOT NULL,
    payee_id      INT UNSIGNED NOT NULL,
    amount        DECIMAL(12,2) NOT NULL,
    period_start  DATE NOT NULL,
    period_end    DATE NOT NULL,
    created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_group_date (group_id, created_at),
    FOREIGN KEY (group_id)   REFERENCES `groups`(id) ON DELETE CASCADE,
    FOREIGN KEY (settled_by) REFERENCES users(id)    ON DELETE CASCADE,
    FOREIGN KEY (payer_id)   REFERENCES users(id)    ON DELETE CASCADE,
    FOREIGN KEY (payee_id)   REFERENCES users(id)    ON DELETE CASCADE
) ENGINE=InnoDB;

-- ============================================================
-- 11. settlement_confirmations (per-member settlement acknowledgements)
--     Each member confirms individually; once all confirm, the
--     settlement is finalized and rows move into `settlements`.
--     period_start / period_end must match the current unsettled
--     period for the confirmation to be valid.
-- ============================================================
CREATE TABLE IF NOT EXISTS settlement_confirmations (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    group_id      INT UNSIGNED NOT NULL,
    user_id       INT UNSIGNED NOT NULL,
    period_start  DATE NOT NULL,
    period_end    DATE NOT NULL,
    confirmed_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    UNIQUE KEY uq_group_user (group_id, user_id),
    FOREIGN KEY (group_id)  REFERENCES `groups`(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id)   REFERENCES users(id)    ON DELETE CASCADE
) ENGINE=InnoDB;
