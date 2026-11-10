SET @sql = (SELECT IF(COUNT(*) = 0,
    'ALTER TABLE lookups ADD COLUMN parent_id INT UNSIGNED NULL AFTER label',
    'SELECT 1')
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'lookups' AND COLUMN_NAME = 'parent_id');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

CREATE TABLE IF NOT EXISTS meeting_trips (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    meeting_id BIGINT UNSIGNED NOT NULL,
    position INT UNSIGNED NOT NULL DEFAULT 0,
    unit_id INT UNSIGNED NULL,
    date_text VARCHAR(100) NOT NULL DEFAULT '',
    group_id INT UNSIGNED NULL,
    destination VARCHAR(255) NOT NULL DEFAULT '',
    leader VARCHAR(255) NOT NULL DEFAULT '',
    INDEX idx_trips_meeting (meeting_id, position),
    CONSTRAINT fk_trips_meeting FOREIGN KEY (meeting_id) REFERENCES meetings (id) ON DELETE CASCADE,
    CONSTRAINT fk_trips_unit FOREIGN KEY (unit_id) REFERENCES lookups (id) ON DELETE SET NULL,
    CONSTRAINT fk_trips_group FOREIGN KEY (group_id) REFERENCES lookups (id) ON DELETE SET NULL
) ENGINE=InnoDB CHARACTER SET utf8mb4 COLLATE utf8mb4_czech_ci;
