CREATE TABLE IF NOT EXISTS known_roles (
    role_key VARCHAR(100) NOT NULL PRIMARY KEY,
    label VARCHAR(255) NOT NULL DEFAULT '',
    last_seen_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB CHARACTER SET utf8mb4 COLLATE utf8mb4_czech_ci;

CREATE TABLE IF NOT EXISTS module_settings (
    module VARCHAR(50) NOT NULL PRIMARY KEY,
    policy ENUM('all', 'roles') NOT NULL DEFAULT 'roles'
) ENGINE=InnoDB CHARACTER SET utf8mb4 COLLATE utf8mb4_czech_ci;

CREATE TABLE IF NOT EXISTS module_access (
    module VARCHAR(50) NOT NULL,
    role_key VARCHAR(100) NOT NULL,
    PRIMARY KEY (module, role_key)
) ENGINE=InnoDB CHARACTER SET utf8mb4 COLLATE utf8mb4_czech_ci;
