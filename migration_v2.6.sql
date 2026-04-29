-- ============================================================
-- Migration v2.6 — Durable Outbox for Notification Replay
-- ============================================================
-- Adds an outbox table used to persist notification side effects so
-- they can be replayed by a worker when needed.
--
-- The application keeps working if the table is unavailable:
-- outbox enqueue is best-effort, and notification delivery still
-- proceeds synchronously on the request path.
-- ============================================================

USE ExpMgWEB;

CREATE TABLE IF NOT EXISTS outbox_events (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    event_key       CHAR(64) NOT NULL UNIQUE,
    event_type      VARCHAR(80) NOT NULL,
    payload_json    LONGTEXT NOT NULL,
    status          ENUM('pending','processing','retryable','sent','dead') NOT NULL DEFAULT 'pending',
    retry_count     INT UNSIGNED NOT NULL DEFAULT 0,
    next_attempt_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    processing_since TIMESTAMP NULL DEFAULT NULL,
    last_error      VARCHAR(500) DEFAULT NULL,
    processed_at    TIMESTAMP NULL DEFAULT NULL,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    INDEX idx_outbox_status_next (status, next_attempt_at, created_at),
    INDEX idx_outbox_type_created (event_type, created_at)
) ENGINE=InnoDB;

SELECT 'Durable outbox for notification replay (v2.6) installed' AS status;
