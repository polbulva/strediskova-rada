SET @sql = (SELECT IF(COUNT(*) = 0,
    'ALTER TABLE inventory_items ADD COLUMN warehouse_id INT UNSIGNED NULL AFTER price, ADD CONSTRAINT fk_inventory_warehouse FOREIGN KEY (warehouse_id) REFERENCES lookups (id) ON DELETE SET NULL',
    'SELECT 1')
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'inventory_items' AND COLUMN_NAME = 'warehouse_id');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- dosavadní textová místa uložení se převedou na sklady v číselníku
INSERT INTO lookups (type, label, position)
SELECT 'warehouse', i.location, (@pos := @pos + 1)
FROM (SELECT DISTINCT location FROM inventory_items WHERE location <> '') i, (SELECT @pos := 0) p
WHERE NOT EXISTS (SELECT 1 FROM lookups l WHERE l.type = 'warehouse' AND l.label = i.location);

UPDATE inventory_items i JOIN lookups l ON l.type = 'warehouse' AND l.label = i.location
SET i.warehouse_id = l.id WHERE i.warehouse_id IS NULL AND i.location <> '';

SET @sql = (SELECT IF(COUNT(*) > 0, 'ALTER TABLE inventory_items DROP COLUMN location', 'SELECT 1')
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'inventory_items' AND COLUMN_NAME = 'location');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- inventární číslo ve tvaru RR-NNN (koncovka roku a pořadí v roce), např. 25-001
SET @sql = (SELECT IF(DATA_TYPE <> 'varchar', 'ALTER TABLE inventory_items MODIFY inventory_number VARCHAR(20) NOT NULL', 'SELECT 1')
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'inventory_items' AND COLUMN_NAME = 'inventory_number');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

UPDATE inventory_items
SET inventory_number = CONCAT(LPAD(MOD(COALESCE(YEAR(acquired_on), YEAR(booked_on), YEAR(created_at)), 100), 2, '0'), '-', LPAD(id, 3, '0'))
WHERE inventory_number NOT REGEXP '^[0-9]{2}-[0-9]{3,}$';
