<?php

const WEEKDAYS = [1 => 'Pondělí', 2 => 'Úterý', 3 => 'Středa', 4 => 'Čtvrtek', 5 => 'Pátek', 6 => 'Sobota', 7 => 'Neděle'];

/** Typy číselníků editovatelné v administraci. */
function lookupTypes(): array
{
    return [
        'category' => 'Kategorie',
        'clubroom' => 'Klubovny',
        'unit' => 'Oddíly',
        'group' => 'Družiny',
    ];
}

function loadLookups(PDO $pdo, string $type): array
{
    $statement = $pdo->prepare('SELECT id, label, parent_id FROM lookups WHERE type = :type ORDER BY position, id');
    $statement->execute(['type' => $type]);
    return $statement->fetchAll();
}

/**
 * @param array $existing  [id => ['label' => ..., 'delete' => ...]] v pořadí zobrazení
 * @param array $new       seznam nových názvů
 */
function saveLookups(PDO $pdo, string $type, array $existing, array $new, array $newParents = []): void
{
    $pdo->beginTransaction();
    try {
        $position = 0;
        $update = $pdo->prepare('UPDATE lookups SET label = :label, position = :position WHERE id = :id AND type = :type');
        $updateGroup = $pdo->prepare('UPDATE lookups SET label = :label, position = :position, parent_id = :parent WHERE id = :id AND type = :type');
        $delete = $pdo->prepare('DELETE FROM lookups WHERE id = :id AND type = :type');
        foreach ($existing as $id => $row) {
            if (!ctype_digit((string) $id) || !is_array($row)) {
                continue;
            }
            $label = mb_substr(trim((string) ($row['label'] ?? '')), 0, 100);
            if (!empty($row['delete']) || $label === '') {
                $delete->execute(['id' => (int) $id, 'type' => $type]);
                if ($type === 'unit') {
                    $pdo->prepare("UPDATE lookups SET parent_id = NULL WHERE type = 'group' AND parent_id = :id")->execute(['id' => (int) $id]);
                }
                continue;
            }
            if ($type === 'group') {
                $updateGroup->execute(['label' => $label, 'position' => ++$position, 'parent' => lookupIdOrNull($pdo, 'unit', $row['parent'] ?? ''), 'id' => (int) $id, 'type' => $type]);
                continue;
            }
            $update->execute(['label' => $label, 'position' => ++$position, 'id' => (int) $id, 'type' => $type]);
        }
        $insert = $pdo->prepare('INSERT INTO lookups (type, label, position, parent_id) VALUES (:type, :label, :position, :parent)');
        foreach (array_values($new) as $newIndex => $label) {
            $label = mb_substr(trim((string) $label), 0, 100);
            if ($label !== '') {
                $insert->execute(['type' => $type, 'label' => $label, 'position' => ++$position, 'parent' => $type === 'group' ? lookupIdOrNull($pdo, 'unit', $newParents[$newIndex] ?? '') : null]);
            }
        }
        $pdo->commit();
    } catch (Throwable $exception) {
        $pdo->rollBack();
        throw $exception;
    }
}

function previousSeason(string $season): string
{
    $start = (int) substr($season, 0, 4) - 1;
    return $start . '/' . ($start + 1);
}

function isValidSeason(string $season): bool
{
    return (bool) preg_match('~^(\d{4})/(\d{4})$~', $season, $m) && (int) $m[2] === (int) $m[1] + 1;
}

/** Sezóny s daty + aktuální a následující, nejnovější první. */
function scheduleSeasons(PDO $pdo, string $current): array
{
    $seasons = $pdo->query('SELECT DISTINCT season FROM group_meetings')->fetchAll(PDO::FETCH_COLUMN);
    $start = (int) substr($current, 0, 4);
    $seasons[] = $current;
    $seasons[] = ($start + 1) . '/' . ($start + 2);
    $seasons = array_values(array_unique($seasons));
    rsort($seasons);
    return $seasons;
}

function loadGroupMeetings(PDO $pdo, string $season): array
{
    $statement = $pdo->prepare(
        'SELECT g.*, c.label AS category_label, r.label AS clubroom_label
         FROM group_meetings g
         LEFT JOIN lookups c ON c.id = g.category_id
         LEFT JOIN lookups r ON r.id = g.clubroom_id
         WHERE g.season = :season
         ORDER BY g.weekday, g.time_from IS NULL, g.time_from, g.group_name'
    );
    $statement->execute(['season' => $season]);
    return $statement->fetchAll();
}

function lookupIdOrNull(PDO $pdo, string $type, mixed $value): ?int
{
    if (!ctype_digit((string) $value)) {
        return null;
    }
    $statement = $pdo->prepare('SELECT id FROM lookups WHERE id = :id AND type = :type');
    $statement->execute(['id' => (int) $value, 'type' => $type]);
    return $statement->fetchColumn() ? (int) $value : null;
}

/** @return string|null chybová zpráva */
function saveGroupMeeting(PDO $pdo, string $season, array $in): ?string
{
    $name = mb_substr(trim((string) ($in['group_name'] ?? '')), 0, 255);
    $weekday = (int) ($in['weekday'] ?? 0);
    $from = trim((string) ($in['time_from'] ?? ''));
    $to = trim((string) ($in['time_to'] ?? ''));

    if ($name === '' || !isset(WEEKDAYS[$weekday]) || !isValidSeason($season)) {
        return 'Vyplňte název družiny a den.';
    }
    if (($from !== '' && !isValidTime($from)) || ($to !== '' && !isValidTime($to))) {
        return 'Zkontrolujte zadaný čas.';
    }

    $params = [
        'clubroom_id' => lookupIdOrNull($pdo, 'clubroom', $in['clubroom_id'] ?? ''),
        'weekday' => $weekday,
        'time_from' => $from ?: null,
        'time_to' => $to ?: null,
        'group_name' => $name,
        'category_id' => lookupIdOrNull($pdo, 'category', $in['category_id'] ?? ''),
        'age_text' => mb_substr(trim((string) ($in['age_text'] ?? '')), 0, 100),
        'leaders' => mb_substr(trim((string) ($in['leaders'] ?? '')), 0, 255),
    ];

    if (ctype_digit((string) ($in['id'] ?? '')) && (int) $in['id'] > 0) {
        $params['id'] = (int) $in['id'];
        $params['season'] = $season;
        $pdo->prepare(
            'UPDATE group_meetings SET clubroom_id = :clubroom_id, weekday = :weekday, time_from = :time_from,
                time_to = :time_to, group_name = :group_name, category_id = :category_id, age_text = :age_text,
                leaders = :leaders WHERE id = :id AND season = :season'
        )->execute($params);
    } else {
        $params['season'] = $season;
        $pdo->prepare(
            'INSERT INTO group_meetings (season, clubroom_id, weekday, time_from, time_to, group_name, category_id, age_text, leaders)
             VALUES (:season, :clubroom_id, :weekday, :time_from, :time_to, :group_name, :category_id, :age_text, :leaders)'
        )->execute($params);
    }
    return null;
}

function deleteGroupMeeting(PDO $pdo, int $id): void
{
    $pdo->prepare('DELETE FROM group_meetings WHERE id = :id')->execute(['id' => $id]);
}

function moveGroupMeeting(PDO $pdo, int $id, int $weekday, mixed $clubroom): bool
{
    if (!isset(WEEKDAYS[$weekday])) {
        return false;
    }
    $pdo->prepare('UPDATE group_meetings SET weekday = :weekday, clubroom_id = :clubroom WHERE id = :id')
        ->execute(['weekday' => $weekday, 'clubroom' => lookupIdOrNull($pdo, 'clubroom', $clubroom), 'id' => $id]);
    return true;
}

/** Zkopíruje schůzky z jedné sezóny do druhé. Vrací počet zkopírovaných řádků. */
function copySeason(PDO $pdo, string $from, string $to): int
{
    $statement = $pdo->prepare(
        'INSERT INTO group_meetings (season, clubroom_id, weekday, time_from, time_to, group_name, category_id, age_text, leaders)
         SELECT :to, clubroom_id, weekday, time_from, time_to, group_name, category_id, age_text, leaders
         FROM group_meetings WHERE season = :from'
    );
    $statement->execute(['to' => $to, 'from' => $from]);
    return $statement->rowCount();
}

function meetingCardHtml(array $m, bool $editable): string
{
    $time = $m['time_from'] ? substr($m['time_from'], 0, 5) . ($m['time_to'] ? '–' . substr($m['time_to'], 0, 5) : '') : '';
    $hue = $m['category_id'] ? ((int) $m['category_id'] * 67) % 360 : 150;
    $data = $editable ? ' draggable="true" data-sch-card data-sch-json="' . h(json_encode([
        'id' => (int) $m['id'],
        'clubroom_id' => $m['clubroom_id'],
        'weekday' => (int) $m['weekday'],
        'time_from' => $m['time_from'] ? substr($m['time_from'], 0, 5) : '',
        'time_to' => $m['time_to'] ? substr($m['time_to'], 0, 5) : '',
        'group_name' => $m['group_name'],
        'category_id' => $m['category_id'],
        'age_text' => $m['age_text'],
        'leaders' => $m['leaders'],
    ], JSON_UNESCAPED_UNICODE)) . '" data-sch-id="' . (int) $m['id'] . '"' : '';

    $meta = trim(($m['category_label'] ?? '') . ($m['age_text'] !== '' ? ' · ' . $m['age_text'] : ''), ' ·');
    return '<div class="tt-card' . ($editable ? ' editable' : '') . '" style="border-left-color:hsl(' . $hue . ',55%,42%)"' . $data . '>'
        . ($time !== '' ? '<span class="tt-time">' . h($time) . '</span>' : '')
        . '<strong>' . h($m['group_name']) . '</strong>'
        . ($meta !== '' ? '<span class="tt-meta">' . h($meta) . '</span>' : '')
        . ($m['leaders'] !== '' ? '<span class="tt-leaders">' . h($m['leaders']) . '</span>' : '')
        . '</div>';
}

/** Rozvrh schůzek ve stylu školního rozvrhu, pro každou klubovnu zvlášť. */
function renderTimetables(array $clubrooms, array $meetings, bool $editable): string
{
    $byRoom = [];
    foreach ($meetings as $m) {
        $byRoom[(int) $m['clubroom_id']][(int) $m['weekday']][] = $m;
    }

    $sections = [];
    foreach ($clubrooms as $room) {
        $sections[(int) $room['id']] = $room['label'];
    }
    if (isset($byRoom[0])) {
        $sections[0] = 'Bez klubovny';
    }

    $html = '';
    foreach ($sections as $roomId => $roomLabel) {
        if (!$editable && empty($byRoom[$roomId])) {
            continue;
        }
        $days = [1, 2, 3, 4, 5];
        foreach ([6, 7] as $weekend) {
            if (!empty($byRoom[$roomId][$weekend])) {
                $days[] = $weekend;
            }
        }
        $html .= '<section class="timetable"><h3>' . h($roomLabel) . '</h3><div class="tt-grid">';
        foreach ($days as $day) {
            $html .= '<div class="tt-day" data-sch-day="' . $day . '" data-sch-room="' . ($roomId ?: '') . '">'
                . '<div class="tt-head"><span>' . WEEKDAYS[$day] . '</span>'
                . ($editable ? '<button type="button" class="tt-add" data-sch-add title="Přidat schůzku">+</button>' : '')
                . '</div><div class="tt-body">';
            foreach ($byRoom[$roomId][$day] ?? [] as $m) {
                $html .= meetingCardHtml($m, $editable);
            }
            $html .= '</div></div>';
        }
        $html .= '</div></section>';
    }

    return $html !== '' ? $html : '<p class="muted">Zatím nejsou definovány žádné klubovny. Přidejte je v Administraci → Číselníky.</p>';
}

function renderScheduleDialog(string $season, array $clubrooms, array $categories, array $groups = []): string
{
    $options = static function (array $rows): string {
        $html = '<option value="">–</option>';
        foreach ($rows as $row) {
            $html .= '<option value="' . (int) $row['id'] . '">' . h($row['label']) . '</option>';
        }
        return $html;
    };
    $days = '';
    foreach (WEEKDAYS as $number => $name) {
        $days .= '<option value="' . $number . '">' . $name . '</option>';
    }

    return '<dialog id="sch-dialog"><form method="post" id="sch-form">'
        . '<input type="hidden" name="csrf_token" value="' . h(csrfToken()) . '">'
        . '<input type="hidden" name="season" value="' . h($season) . '">'
        . '<input type="hidden" name="id" value="">'
        . '<h3 id="sch-title">Schůzka družiny</h3>'
        . '<label>Družina <input type="text" name="group_name" maxlength="255" list="group-names" required></label>'
        . '<datalist id="group-names">' . implode('', array_map(static fn (array $g): string => '<option value="' . h($g['label']) . '">', $groups)) . '</datalist>'
        . '<div class="form-grid">'
        . '<label>Klubovna <select name="clubroom_id">' . $options($clubrooms) . '</select></label>'
        . '<label>Den <select name="weekday">' . $days . '</select></label>'
        . '<label>Čas od <input type="time" name="time_from"></label>'
        . '<label>Čas do <input type="time" name="time_to"></label>'
        . '<label>Kategorie <select name="category_id">' . $options($categories) . '</select></label>'
        . '<label>Věk / třída <input type="text" name="age_text" maxlength="100" placeholder="např. 1.–3. třída"></label>'
        . '</div>'
        . '<label>Vedoucí <input type="text" name="leaders" maxlength="255"></label>'
        . '<div class="dialog-actions">'
        . '<button class="button" type="submit" name="action" value="save">Uložit</button>'
        . '<button class="button danger" type="submit" name="action" value="delete" id="sch-delete" formnovalidate>Smazat</button>'
        . '<button class="button secondary" type="button" id="sch-cancel">Zrušit</button>'
        . '</div></form></dialog>';
}

/** Rozvrh pro zobrazení v zápisu (jen ke čtení). */
/** Tabulková varianta rozvrhu pro PDF (Dompdf nezná CSS grid). */
function renderTimetablesPdf(array $clubrooms, array $meetings): string
{
    $sections = [];
    foreach ($clubrooms as $room) {
        $sections[(int) $room['id']] = $room['label'];
    }
    $byRoom = [];
    foreach ($meetings as $m) {
        $byRoom[(int) $m['clubroom_id']][(int) $m['weekday']][] = $m;
        if (!isset($sections[(int) $m['clubroom_id']])) {
            $sections[(int) $m['clubroom_id']] = 'Bez klubovny';
        }
    }

    $html = '';
    foreach ($sections as $roomId => $roomLabel) {
        if (empty($byRoom[$roomId])) {
            continue;
        }
        $html .= '<p class="pdf-room"><strong>' . h($roomLabel) . '</strong></p><table class="doc-table"><tbody>';
        foreach (WEEKDAYS as $day => $dayName) {
            if (empty($byRoom[$roomId][$day])) {
                continue;
            }
            $cells = [];
            foreach ($byRoom[$roomId][$day] as $m) {
                $time = $m['time_from'] ? substr($m['time_from'], 0, 5) . ($m['time_to'] ? '–' . substr($m['time_to'], 0, 5) : '') : '';
                $meta = trim(($m['category_label'] ?? '') . ($m['age_text'] !== '' ? ' · ' . $m['age_text'] : ''), ' ·');
                $cells[] = '<strong>' . h(trim($time . ' ' . $m['group_name'])) . '</strong>'
                    . ($meta !== '' ? ' <span class="muted">(' . h($meta) . ')</span>' : '')
                    . ($m['leaders'] !== '' ? '<br>' . h($m['leaders']) : '');
            }
            $html .= '<tr><th>' . h($dayName) . '</th><td>' . implode('<br><br>', $cells) . '</td></tr>';
        }
        $html .= '</tbody></table>';
    }
    return $html;
}
function renderSeasonTimetables(string $season, bool $forPdf = false): string
{
    try {
        $pdo = databaseConnection();
        $meetings = loadGroupMeetings($pdo, $season);
        if (!$meetings) {
            return '<p class="muted">Rozvrh schůzek pro sezónu ' . h($season) . ' zatím není vyplněn.</p>';
        }
        if ($forPdf) {
            return renderTimetablesPdf(loadLookups($pdo, 'clubroom'), $meetings);
        }
        return '<div class="tt-wrap">' . renderTimetables(loadLookups($pdo, 'clubroom'), $meetings, false) . '</div>';
    } catch (Throwable $exception) {
        return '<p class="muted">Rozvrh schůzek se nepodařilo načíst.</p>';
    }
}
