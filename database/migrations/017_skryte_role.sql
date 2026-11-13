SET @sql = (SELECT IF(COUNT(*) = 0,
    'ALTER TABLE known_roles ADD COLUMN hidden TINYINT(1) NOT NULL DEFAULT 0',
    'SELECT 1')
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'known_roles' AND COLUMN_NAME = 'hidden');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
