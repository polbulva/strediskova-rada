CREATE TABLE IF NOT EXISTS app_settings (
    setting_key VARCHAR(50) NOT NULL PRIMARY KEY,
    setting_value TEXT NULL
) ENGINE=InnoDB CHARACTER SET utf8mb4 COLLATE utf8mb4_czech_ci;

CREATE TABLE IF NOT EXISTS group_schedule (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    position INT UNSIGNED NOT NULL DEFAULT 0,
    location VARCHAR(255) NOT NULL DEFAULT '',
    day VARCHAR(255) NOT NULL DEFAULT '',
    time_text VARCHAR(255) NOT NULL DEFAULT '',
    group_name VARCHAR(255) NOT NULL,
    category VARCHAR(255) NOT NULL DEFAULT '',
    leaders VARCHAR(255) NOT NULL DEFAULT ''
) ENGINE=InnoDB CHARACTER SET utf8mb4 COLLATE utf8mb4_czech_ci;

SET @sql = (SELECT IF(COUNT(*) = 0,
    'ALTER TABLE meetings ADD COLUMN calendar_event_key VARCHAR(255) NULL AFTER verifier',
    'SELECT 1')
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'meetings' AND COLUMN_NAME = 'calendar_event_key');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(COUNT(*) = 0,
    'ALTER TABLE meetings ADD UNIQUE INDEX uq_meetings_calendar_event (calendar_event_key)',
    'SELECT 1')
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'meetings' AND INDEX_NAME = 'uq_meetings_calendar_event');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
