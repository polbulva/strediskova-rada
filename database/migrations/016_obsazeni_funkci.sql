CREATE TABLE IF NOT EXISTS unit_functions (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    skautis_function_id INT NOT NULL,
    person_name VARCHAR(255) NOT NULL,
    function_name VARCHAR(255) NOT NULL,
    unit_name VARCHAR(255) NOT NULL DEFAULT '',
    valid_from DATE NULL,
    valid_to DATE NULL,
    synced_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_unit_function (skautis_function_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;
