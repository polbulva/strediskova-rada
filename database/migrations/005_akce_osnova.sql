CREATE TABLE IF NOT EXISTS agenda_template (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    level TINYINT UNSIGNED NOT NULL DEFAULT 1,
    title VARCHAR(255) NOT NULL,
    position INT UNSIGNED NOT NULL DEFAULT 0
) ENGINE=InnoDB CHARACTER SET utf8mb4 COLLATE utf8mb4_czech_ci;

INSERT INTO agenda_template (level, title, position)
SELECT t.level, t.title, t.position FROM (
    SELECT 1 AS level, 'Zprávy z VOJ' AS title, 1 AS position
    UNION ALL SELECT 1, 'Družinové záležitosti', 2
    UNION ALL SELECT 2, 'Vedoucí družin a družiny', 3
    UNION ALL SELECT 2, 'Informace z oddílů', 4
    UNION ALL SELECT 2, 'Výpravy', 5
    UNION ALL SELECT 1, 'Roverský kmen', 6
    UNION ALL SELECT 1, 'Prezentace střediska', 7
    UNION ALL SELECT 1, 'Hospodaření', 8
    UNION ALL SELECT 1, 'Dotace', 9
    UNION ALL SELECT 1, 'Vzdělávání činovníků', 10
    UNION ALL SELECT 2, 'Čekatelské zkoušky', 11
    UNION ALL SELECT 2, 'Vůdcovky', 12
    UNION ALL SELECT 2, 'Zdravoťák', 13
    UNION ALL SELECT 1, 'Chata', 14
    UNION ALL SELECT 2, 'Oprava a údržba', 15
    UNION ALL SELECT 2, 'Úklid na chatě', 16
    UNION ALL SELECT 2, 'Rezervace', 17
    UNION ALL SELECT 1, 'Proběhlé akce', 18
    UNION ALL SELECT 1, 'Nejbližší chystané akce', 19
    UNION ALL SELECT 1, 'Různé', 20
) t
WHERE NOT EXISTS (SELECT 1 FROM agenda_template);

CREATE TABLE IF NOT EXISTS events (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    event_date DATE NOT NULL,
    event_time TIME NULL,
    patron VARCHAR(255) NOT NULL DEFAULT '',
    notes TEXT NULL,
    evaluation TEXT NULL,
    evaluated_meeting_id BIGINT UNSIGNED NULL,
    INDEX idx_events_date (event_date),
    CONSTRAINT fk_events_evaluated_meeting FOREIGN KEY (evaluated_meeting_id) REFERENCES meetings (id) ON DELETE SET NULL
) ENGINE=InnoDB CHARACTER SET utf8mb4 COLLATE utf8mb4_czech_ci;

CREATE TABLE IF NOT EXISTS meeting_events (
    meeting_id BIGINT UNSIGNED NOT NULL,
    event_id INT UNSIGNED NOT NULL,
    section ENUM('upcoming', 'past') NOT NULL,
    PRIMARY KEY (meeting_id, event_id),
    CONSTRAINT fk_meeting_events_meeting FOREIGN KEY (meeting_id) REFERENCES meetings (id) ON DELETE CASCADE,
    CONSTRAINT fk_meeting_events_event FOREIGN KEY (event_id) REFERENCES events (id) ON DELETE CASCADE
) ENGINE=InnoDB CHARACTER SET utf8mb4 COLLATE utf8mb4_czech_ci;
