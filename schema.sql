CREATE TABLE meeting_minutes (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    meeting_date DATE NOT NULL,
    place VARCHAR(255) NOT NULL,
    attendees TEXT NOT NULL,
    absentees TEXT NULL,
    chair VARCHAR(255) NULL,
    secretary VARCHAR(255) NULL,
    agenda TEXT NOT NULL,
    minutes TEXT NOT NULL,
    tasks TEXT NULL,
    next_meeting DATE NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) CHARACTER SET utf8mb4 COLLATE utf8mb4_czech_ci;
