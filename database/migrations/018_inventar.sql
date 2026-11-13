CREATE TABLE IF NOT EXISTS inventory_items (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    inventory_number INT UNSIGNED NOT NULL,
    name VARCHAR(255) NOT NULL,
    acquired_on DATE NULL,
    booked_on DATE NULL,
    document VARCHAR(100) NOT NULL DEFAULT '',
    price DECIMAL(12,2) NULL,
    location VARCHAR(100) NOT NULL DEFAULT '',
    disposed_on DATE NULL,
    disposal_note VARCHAR(255) NOT NULL DEFAULT '',
    disposal_price DECIMAL(12,2) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_inventory_number (inventory_number)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;
