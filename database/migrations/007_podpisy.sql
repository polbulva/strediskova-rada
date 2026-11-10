SET @sql = (SELECT IF(COUNT(*) = 0,
    'ALTER TABLE meetings ADD COLUMN author_signature MEDIUMTEXT NULL, ADD COLUMN verifier_signature MEDIUMTEXT NULL',
    'SELECT 1')
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'meetings' AND COLUMN_NAME = 'author_signature');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
