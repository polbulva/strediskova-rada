<?php

/** Datum Velikonoční neděle (Meeusův/Jonesův algoritmus). */
function easterSunday(int $year): DateTimeImmutable
{
    $a = $year % 19;
    $b = intdiv($year, 100);
    $c = $year % 100;
    $d = intdiv($b, 4);
    $e = $b % 4;
    $f = intdiv($b + 8, 25);
    $g = intdiv($b - $f + 1, 3);
    $h = (19 * $a + $b - $d - $g + 15) % 30;
    $i = intdiv($c, 4);
    $k = $c % 4;
    $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7;
    $m = intdiv($a + 11 * $h + 22 * $l, 451);
    $month = intdiv($h + $l - 7 * $m + 114, 31);
    $day = (($h + $l - 7 * $m + 114) % 31) + 1;
    return new DateTimeImmutable(sprintf('%04d-%02d-%02d', $year, $month, $day));
}

/** Státní svátky a dny pracovního klidu v ČR (zákon č. 245/2000 Sb.); pevná data + Velikonoce dopočtem. */
function czechHolidays(int $year): array
{
    $easter = easterSunday($year);
    $days = [
        sprintf('%04d-01-01', $year) => 'Nový rok, Den obnovy samostatného českého státu',
        $easter->modify('-2 days')->format('Y-m-d') => 'Velký pátek',
        $easter->modify('+1 day')->format('Y-m-d') => 'Velikonoční pondělí',
        sprintf('%04d-05-01', $year) => 'Svátek práce',
        sprintf('%04d-05-08', $year) => 'Den vítězství',
        sprintf('%04d-07-05', $year) => 'Den slovanských věrozvěstů Cyrila a Metoděje',
        sprintf('%04d-07-06', $year) => 'Den upálení mistra Jana Husa',
        sprintf('%04d-09-28', $year) => 'Den české státnosti',
        sprintf('%04d-10-28', $year) => 'Den vzniku samostatného československého státu',
        sprintf('%04d-11-17', $year) => 'Den boje za svobodu a demokracii',
        sprintf('%04d-12-24', $year) => 'Štědrý den',
        sprintf('%04d-12-25', $year) => '1. svátek vánoční',
        sprintf('%04d-12-26', $year) => '2. svátek vánoční',
    ];
    ksort($days);
    return $days;
}

/** Hranice sezóny (září–srpen): [od, do]. */
function seasonRange(string $season): array
{
    $start = (int) substr($season, 0, 4);
    return [$start . '-09-01', ($start + 1) . '-08-31'];
}

function loadPlanEvents(PDO $pdo, string $season): array
{
    [$from, $to] = seasonRange($season);
    $statement = $pdo->prepare('SELECT * FROM events WHERE event_date BETWEEN :f AND :t ORDER BY event_date, event_time, id');
    $statement->execute(['f' => $from, 't' => $to]);
    return $statement->fetchAll();
}

function loadSchoolBreaks(PDO $pdo, string $season): array
{
    [$from, $to] = seasonRange($season);
    $statement = $pdo->prepare('SELECT * FROM school_breaks WHERE date_to >= :f AND date_from <= :t ORDER BY date_from');
    $statement->execute(['f' => $from, 't' => $to]);
    return $statement->fetchAll();
}

function planSeasons(PDO $pdo, string $current): array
{
    $seasons = [$current];
    $start = (int) substr($current, 0, 4);
    $seasons[] = ($start + 1) . '/' . ($start + 2);
    foreach ($pdo->query('SELECT DISTINCT event_date FROM events')->fetchAll(PDO::FETCH_COLUMN) as $date) {
        $seasons[] = seasonFor((string) $date);
    }
    $seasons = array_values(array_unique($seasons));
    rsort($seasons);
    return $seasons;
}

/** Uloží (nebo vloží) akci z plánu; vrací ID akce. */
function savePlanEvent(PDO $pdo, array $event): int
{
    $params = [
        'name' => $event['name'],
        'date' => $event['event_date'],
        'time' => $event['event_time'] ?: null,
        'patron' => $event['patron'],
        'notes' => $event['notes'] ?: null,
    ];
    if ($event['id']) {
        $pdo->prepare('UPDATE events SET name = :name, event_date = :date, event_time = :time, patron = :patron, notes = :notes WHERE id = :id')
            ->execute($params + ['id' => $event['id']]);
        return (int) $event['id'];
    }
    $pdo->prepare('INSERT INTO events (name, event_date, event_time, patron, notes) VALUES (:name, :date, :time, :patron, :notes)')
        ->execute($params);
    return (int) $pdo->lastInsertId();
}

/** Položky plánu seřazené po měsících: [Y-m => [[date, to, kind, label, ...], ...]]. */
function buildPlanTimeline(string $season, array $events, array $breaks): array
{
    [$from, $to] = seasonRange($season);
    $start = (int) substr($season, 0, 4);
    $entries = [];
    foreach ($events as $event) {
        $entries[] = ['date' => $event['event_date'], 'to' => null, 'kind' => 'event', 'event' => $event];
    }
    foreach ([$start, $start + 1] as $year) {
        foreach (czechHolidays($year) as $date => $name) {
            if ($date >= $from && $date <= $to) {
                $entries[] = ['date' => $date, 'to' => null, 'kind' => 'holiday', 'label' => $name];
            }
        }
    }
    foreach ($breaks as $break) {
        $entries[] = ['date' => max($break['date_from'], $from), 'to' => $break['date_to'], 'kind' => 'break', 'label' => $break['name'], 'id' => (int) $break['id'], 'from_real' => $break['date_from']];
    }
    usort($entries, static fn (array $a, array $b): int => [$a['date'], $a['kind'] === 'event' ? 1 : 0] <=> [$b['date'], $b['kind'] === 'event' ? 1 : 0]);

    $byMonth = [];
    foreach ($entries as $entry) {
        $byMonth[substr($entry['date'], 0, 7)][] = $entry;
    }
    return $byMonth;
}

const MONTH_NAMES = [1 => 'leden', 2 => 'únor', 3 => 'březen', 4 => 'duben', 5 => 'květen', 6 => 'červen', 7 => 'červenec', 8 => 'srpen', 9 => 'září', 10 => 'říjen', 11 => 'listopad', 12 => 'prosinec'];
