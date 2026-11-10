CREATE TABLE IF NOT EXISTS meetings (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    meeting_number INT UNSIGNED NOT NULL,
    season VARCHAR(9) NOT NULL,
    meeting_date DATE NOT NULL,
    time_from TIME NULL,
    time_to TIME NULL,
    place VARCHAR(255) NOT NULL,
    attendees TEXT NULL,
    online_attendees TEXT NULL,
    absentees TEXT NULL,
    next_meeting_date DATE NULL,
    next_meeting_time TIME NULL,
    author VARCHAR(255) NULL,
    verifier VARCHAR(255) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_meetings_date (meeting_date)
) ENGINE=InnoDB CHARACTER SET utf8mb4 COLLATE utf8mb4_czech_ci;

CREATE TABLE IF NOT EXISTS meeting_items (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    meeting_id BIGINT UNSIGNED NOT NULL,
    position INT UNSIGNED NOT NULL,
    level TINYINT UNSIGNED NOT NULL DEFAULT 1,
    title VARCHAR(255) NOT NULL,
    notes MEDIUMTEXT NULL,
    carry_over TINYINT(1) NOT NULL DEFAULT 0,
    INDEX idx_items_meeting (meeting_id, position),
    CONSTRAINT fk_items_meeting FOREIGN KEY (meeting_id) REFERENCES meetings (id) ON DELETE CASCADE
) ENGINE=InnoDB CHARACTER SET utf8mb4 COLLATE utf8mb4_czech_ci;

CREATE TABLE IF NOT EXISTS meeting_resolutions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    meeting_id BIGINT UNSIGNED NOT NULL,
    position INT UNSIGNED NOT NULL,
    text TEXT NOT NULL,
    votes_for INT UNSIGNED NULL,
    votes_against INT UNSIGNED NULL,
    votes_abstain INT UNSIGNED NULL,
    INDEX idx_resolutions_meeting (meeting_id, position),
    CONSTRAINT fk_resolutions_meeting FOREIGN KEY (meeting_id) REFERENCES meetings (id) ON DELETE CASCADE
) ENGINE=InnoDB CHARACTER SET utf8mb4 COLLATE utf8mb4_czech_ci;
