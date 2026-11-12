CREATE TABLE IF NOT EXISTS school_breaks (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    date_from DATE NOT NULL,
    date_to DATE NOT NULL,
    INDEX idx_school_breaks_from (date_from)
) ENGINE=InnoDB CHARACTER SET utf8mb4 COLLATE utf8mb4_czech_ci;

INSERT INTO school_breaks (name, date_from, date_to)
SELECT s.n, s.f, s.t FROM (
    SELECT 'Podzimní prázdniny' AS n, '2026-10-29' AS f, '2026-10-30' AS t
    UNION ALL SELECT 'Vánoční prázdniny', '2026-12-23', '2027-01-03'
    UNION ALL SELECT 'Pololetní prázdniny', '2027-01-29', '2027-01-29'
    UNION ALL SELECT 'Velikonoční prázdniny', '2027-04-01', '2027-04-01'
    UNION ALL SELECT 'Letní prázdniny', '2027-07-01', '2027-08-31'
) AS s
WHERE NOT EXISTS (SELECT 1 FROM school_breaks);
