SET @sql = (SELECT IF(COUNT(*) = 0,
    'ALTER TABLE agenda_template ADD COLUMN always_carry TINYINT(1) NOT NULL DEFAULT 0 AFTER title',
    'SELECT 1')
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'agenda_template' AND COLUMN_NAME = 'always_carry');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;