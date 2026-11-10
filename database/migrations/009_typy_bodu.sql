SET @sql = (SELECT IF(COUNT(*) = 0,
    'ALTER TABLE agenda_template ADD COLUMN item_type VARCHAR(20) NOT NULL DEFAULT ''''',
    'SELECT 1')
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'agenda_template' AND COLUMN_NAME = 'item_type');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(COUNT(*) = 0,
    'ALTER TABLE meeting_items ADD COLUMN item_type VARCHAR(20) NOT NULL DEFAULT ''''',
    'SELECT 1')
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'meeting_items' AND COLUMN_NAME = 'item_type');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

UPDATE agenda_template SET item_type = 'trips' WHERE item_type = '' AND LOWER(title) = 'výpravy';
UPDATE agenda_template SET item_type = 'timetable' WHERE item_type = '' AND LOWER(title) = 'vedoucí družin a družiny';
UPDATE agenda_template SET item_type = 'past' WHERE item_type = '' AND LOWER(title) LIKE '%proběhlé akce%';
UPDATE agenda_template SET item_type = 'upcoming' WHERE item_type = '' AND LOWER(title) LIKE '%chystané akce%';

UPDATE meeting_items SET item_type = 'trips' WHERE item_type = '' AND LOWER(title) = 'výpravy';
UPDATE meeting_items SET item_type = 'timetable' WHERE item_type = '' AND LOWER(title) = 'vedoucí družin a družiny';
UPDATE meeting_items SET item_type = 'past' WHERE item_type = '' AND LOWER(title) LIKE '%proběhlé akce%';
UPDATE meeting_items SET item_type = 'upcoming' WHERE item_type = '' AND LOWER(title) LIKE '%chystané akce%';