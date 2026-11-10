CREATE TABLE IF NOT EXISTS lookups (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    type VARCHAR(30) NOT NULL,
    label VARCHAR(100) NOT NULL,
    position INT UNSIGNED NOT NULL DEFAULT 0,
    INDEX idx_lookups_type (type, position)
) ENGINE=InnoDB CHARACTER SET utf8mb4 COLLATE utf8mb4_czech_ci;

CREATE TABLE IF NOT EXISTS group_meetings (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    season VARCHAR(9) NOT NULL,
    clubroom_id INT UNSIGNED NULL,
    weekday TINYINT UNSIGNED NOT NULL,
    time_from TIME NULL,
    time_to TIME NULL,
    group_name VARCHAR(255) NOT NULL,
    category_id INT UNSIGNED NULL,
    age_text VARCHAR(100) NOT NULL DEFAULT '',
    leaders VARCHAR(255) NOT NULL DEFAULT '',
    INDEX idx_group_meetings_season (season, clubroom_id, weekday),
    CONSTRAINT fk_group_meetings_clubroom FOREIGN KEY (clubroom_id) REFERENCES lookups (id) ON DELETE SET NULL,
    CONSTRAINT fk_group_meetings_category FOREIGN KEY (category_id) REFERENCES lookups (id) ON DELETE SET NULL
) ENGINE=InnoDB CHARACTER SET utf8mb4 COLLATE utf8mb4_czech_ci;

INSERT INTO lookups (type, label, position)
SELECT 'category', 'Vlče', 1 FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM lookups WHERE type = 'category' AND label = 'Vlče');

INSERT INTO lookups (type, label, position)
SELECT 'category', 'Světluška', 2 FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM lookups WHERE type = 'category' AND label = 'Světluška');

INSERT INTO lookups (type, label, position)
SELECT 'category', 'Skaut', 3 FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM lookups WHERE type = 'category' AND label = 'Skaut');

INSERT INTO lookups (type, label, position)
SELECT 'category', 'Skautka', 4 FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM lookups WHERE type = 'category' AND label = 'Skautka');

INSERT INTO lookups (type, label, position)
SELECT 'clubroom', 'Suchá Loz', 1 FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM lookups WHERE type = 'clubroom' AND label = 'Suchá Loz');

INSERT INTO lookups (type, label, position)
SELECT 'clubroom', 'Bánov', 2 FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM lookups WHERE type = 'clubroom' AND label = 'Bánov');
