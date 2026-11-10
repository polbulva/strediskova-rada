<?php

/** Výpravy zápisu: řádky z formuláře (oddíl, kdy, družina, kam, vedoucí). */
function parseTripRows(mixed $input): array
{
    $rows = [];
    foreach (is_array($input) ? $input : [] as $row) {
        if (!is_array($row)) {
            continue;
        }
        $trip = [
            'unit_id' => ctype_digit((string) ($row['unit_id'] ?? '')) ? (int) $row['unit_id'] : null,
            'date_text' => mb_substr(trim((string) ($row['date_text'] ?? '')), 0, 100),
            'group_id' => ctype_digit((string) ($row['group_id'] ?? '')) ? (int) $row['group_id'] : null,
            'destination' => mb_substr(trim((string) ($row['destination'] ?? '')), 0, 255),
            'leader' => mb_substr(trim((string) ($row['leader'] ?? '')), 0, 255),
        ];
        if ($trip['date_text'] !== '' || $trip['destination'] !== '' || $trip['leader'] !== '' || $trip['group_id'] !== null) {
            $rows[] = $trip;
        }
    }
    return $rows;
}

function loadMeetingTrips(PDO $pdo, int $meetingId): array
{
    $statement = $pdo->prepare(
        'SELECT t.*, g.label AS group_label FROM meeting_trips t
         LEFT JOIN lookups g ON g.id = t.group_id
         WHERE t.meeting_id = :m ORDER BY t.position, t.id'
    );
    $statement->execute(['m' => $meetingId]);
    return $statement->fetchAll();
}

/** Volá se uvnitř transakce ukládání zápisu. */
function saveMeetingTrips(PDO $pdo, int $meetingId, array $trips): void
{
    $pdo->prepare('DELETE FROM meeting_trips WHERE meeting_id = :m')->execute(['m' => $meetingId]);
    $insert = $pdo->prepare(
        'INSERT INTO meeting_trips (meeting_id, position, unit_id, date_text, group_id, destination, leader)
         VALUES (:m, :p, :unit, :date, :grp, :dest, :leader)'
    );
    foreach (array_values($trips) as $position => $trip) {
        $insert->execute([
            'm' => $meetingId,
            'p' => $position,
            'unit' => lookupIdOrNull($pdo, 'unit', $trip['unit_id'] ?? ''),
            'date' => $trip['date_text'],
            'grp' => lookupIdOrNull($pdo, 'group', $trip['group_id'] ?? ''),
            'dest' => $trip['destination'],
            'leader' => $trip['leader'],
        ]);
    }
}

function renderTripRow(string $index, array $trip, array $units, array $groups): string
{
    $v = static fn (string $key): string => h((string) ($trip[$key] ?? ''));
    $options = static function (array $rows, mixed $selected, bool $withParent = false) use ($units): string {
        $html = '<option value="">–</option>';
        foreach ($rows as $row) {
            $parent = '';
            if ($withParent && $row['parent_id']) {
                foreach ($units as $unit) {
                    if ((int) $unit['id'] === (int) $row['parent_id']) {
                        $parent = ' (' . $unit['label'] . ')';
                    }
                }
            }
            $html .= '<option value="' . (int) $row['id'] . '"' . ((string) $selected === (string) $row['id'] ? ' selected' : '') . '>'
                . h($row['label'] . $parent) . '</option>';
        }
        return $html;
    };
    $name = static fn (string $field): string => 'name="trips[' . h($index) . '][' . $field . ']" data-field="' . $field . '"';

    return '<div class="event-row trip-row">'
        . '<div class="event-grid">'
        . '<label>Oddíl <select ' . $name('unit_id') . '>' . $options($units, $trip['unit_id'] ?? '') . '</select></label>'
        . '<label>Kdy <input type="text" ' . $name('date_text') . ' maxlength="100" value="' . $v('date_text') . '" placeholder="např. 14.–16. 11."></label>'
        . '<label>Družina <select ' . $name('group_id') . '>' . $options($groups, $trip['group_id'] ?? '', true) . '</select></label>'
        . '<label>Kam <input type="text" ' . $name('destination') . ' maxlength="255" value="' . $v('destination') . '"></label>'
        . '<label>Kdo vede <input type="text" ' . $name('leader') . ' maxlength="255" value="' . $v('leader') . '"></label>'
        . '</div>'
        . '<button type="button" class="event-remove" data-action="remove" title="Odebrat výpravu">✕</button>'
        . '</div>';
}

/** Jedna tabulka za každý oddíl. */
function renderTripTables(array $trips): string
{
    try {
        $units = loadLookups(databaseConnection(), 'unit');
    } catch (Throwable $exception) {
        $units = [];
    }

    $byUnit = [];
    foreach ($trips as $trip) {
        $byUnit[(int) ($trip['unit_id'] ?? 0)][] = $trip;
    }
    $sections = [];
    foreach ($units as $unit) {
        $sections[(int) $unit['id']] = $unit['label'];
    }
    foreach (array_keys($byUnit) as $unitId) {
        if (!isset($sections[$unitId])) {
            $sections[$unitId] = 'Bez oddílu';
        }
    }

    $html = '';
    foreach ($sections as $unitId => $label) {
        $html .= '<table class="doc-table"><caption>' . h($label) . '</caption>'
            . '<thead><tr><th>Kdy</th><th>Družina</th><th>Kam</th><th>Kdo vede</th></tr></thead><tbody>';
        foreach ($byUnit[$unitId] ?? [] as $trip) {
            $html .= '<tr><td>' . h($trip['date_text']) . '</td><td>' . h((string) ($trip['group_label'] ?? ''))
                . '</td><td>' . h($trip['destination']) . '</td><td>' . h($trip['leader']) . '</td></tr>';
        }
        if (empty($byUnit[$unitId])) {
            $html .= '<tr><td colspan="4" class="muted">Žádné výpravy.</td></tr>';
        }
        $html .= '</tbody></table>';
    }
    return $html;
}
