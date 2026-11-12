SET @sql = (SELECT IF(COUNT(*) = 0,
    'ALTER TABLE events ADD COLUMN ical_key VARCHAR(255) NULL, ADD UNIQUE INDEX uq_events_ical_key (ical_key)',
    'SELECT 1')
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'events' AND COLUMN_NAME = 'ical_key');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
