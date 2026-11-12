INSERT INTO school_breaks (name, date_from, date_to)
SELECT 'Jarní prázdniny (Uherské Hradiště)', '2027-03-08', '2027-03-14'
WHERE NOT EXISTS (SELECT 1 FROM school_breaks WHERE date_from = '2027-03-08');
