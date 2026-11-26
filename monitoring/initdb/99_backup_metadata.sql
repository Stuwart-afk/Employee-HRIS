
-- ============================================================
-- HRIS BACKUP METADATA DATABASE
-- Separate from the application database so restores to `hris`
-- cannot delete backup-management records.
-- ============================================================

CREATE DATABASE IF NOT EXISTS hris_backup_metadata
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE hris_backup_metadata;

-- 1. Backup settings
CREATE TABLE IF NOT EXISTS backup_settings (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    enabled TINYINT(1) NOT NULL DEFAULT 1,
    frequency ENUM('hourly', 'daily', 'weekly') NOT NULL DEFAULT 'daily',
    backup_minute TINYINT UNSIGNED NOT NULL DEFAULT 0,
    backup_time TIME NOT NULL DEFAULT '02:00:00',
    backup_day TINYINT UNSIGNED NOT NULL DEFAULT 1,
    retention_days SMALLINT UNSIGNED NOT NULL DEFAULT 30,
    timezone VARCHAR(64) NOT NULL DEFAULT 'Asia/Manila',
    last_run_at DATETIME NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT chk_backup_minute
        CHECK (backup_minute IN (0, 15, 30, 45)),
    CONSTRAINT chk_backup_day
        CHECK (backup_day BETWEEN 1 AND 7),
    CONSTRAINT chk_backup_retention
        CHECK (retention_days IN (7, 14, 30, 60, 90))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO backup_settings (
    id, enabled, frequency, backup_minute, backup_time,
    backup_day, retention_days, timezone
)
VALUES (1, 1, 'daily', 0, '02:00:00', 1, 30, 'Asia/Manila')
ON DUPLICATE KEY UPDATE id = id;


-- 2. Automatic backup execution history
CREATE TABLE IF NOT EXISTS automatic_backup_logs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    scheduled_for DATETIME NOT NULL,
    started_at DATETIME NULL,
    completed_at DATETIME NULL,
    status ENUM('running', 'success', 'failed') NOT NULL DEFAULT 'running',
    filename VARCHAR(255) NULL,
    file_size_bytes BIGINT UNSIGNED NULL,
    message TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_scheduled_occurrence (scheduled_for)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- 3. Automatic backup schedule change history
CREATE TABLE IF NOT EXISTS backup_schedule_history (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    old_settings JSON NULL,
    new_settings JSON NOT NULL,
    changed_by VARCHAR(100) NULL,
    changed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- 4. Database restore history
CREATE TABLE IF NOT EXISTS restore_history (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    request_id CHAR(36) NOT NULL,
    selected_filename VARCHAR(255) NOT NULL,
    safety_filename VARCHAR(255) NULL,
    status ENUM('running', 'success', 'failed') NOT NULL DEFAULT 'running',
    started_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    completed_at DATETIME NULL,
    message TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_restore_request (request_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- 5. Safety backups created before restore operations
CREATE TABLE IF NOT EXISTS safety_backups (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    restore_history_id BIGINT UNSIGNED NULL,
    filename VARCHAR(255) NOT NULL,
    file_size_bytes BIGINT UNSIGNED NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_safety_filename (filename),
    KEY idx_safety_restore_history (restore_history_id),
    CONSTRAINT fk_safety_restore_history
        FOREIGN KEY (restore_history_id)
        REFERENCES restore_history (id)
        ON DELETE SET NULL
        ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- Grant the existing application user access to the metadata DB.
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, INDEX
ON hris_backup_metadata.*
TO 'hris_app'@'%';
