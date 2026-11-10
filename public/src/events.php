<?php

/** Osnova zápisu: hlavní body z administrace (při prázdné tabulce výchozí program). */
function loadAgendaTemplate(PDO $pdo): array
{
    try {
        $rows = $pdo->query('SELECT level, title, always_carry, item_type FROM agenda_template ORDER BY position, id')->fetchAll();
        if ($rows) {
            return array_map(static fn (array $row): array => [(int) $row['level'], $row['title'], (int) $row['always_carry'], $row['item_type']], $rows);
        }
    } catch (Throwable $exception) {
        // tabulka nemusí existovat
    }
    return DEFAULT_AGENDA;
}

function saveAgendaTemplate(PDO $pdo, array $existing, array $new): void
{
    $pdo->beginTransaction();
    try {
        $position = 0;
        $update = $pdo->prepare('UPDATE agenda_template SET title = :title, level = :level, always_carry = :carry, item_type = :type, position = :position WHERE id = :id');
        $delete = $pdo->prepare('DELETE FROM agenda_template WHERE id = :id');
        foreach ($existing as $id => $row) {
            if (!ctype_digit((string) $id) || !is_array($row)) {
                continue;
            }
            $title = mb_substr(trim((string) ($row['title'] ?? '')), 0, 255);
            if (!empty($row['delete']) || $title === '') {
                $delete->execute(['id' => (int) $id]);
                continue;
            }
            $update->execute([
                'title' => $title,
                'level' => max(1, min(MAX_ITEM_LEVEL, (int) ($row['level'] ?? 1))),
                'carry' => empty($row['carry']) ? 0 : 1,
                'type' => itemTypeOrEmpty($row['type'] ?? ''),
                'position' => ++$position,
                'id' => (int) $id,
            ]);
        }
        $insert = $pdo->prepare('INSERT INTO agenda_template (level, title, always_carry, item_type, position) VALUES (:level, :title, :carry, :type, :position)');
        foreach ($new as $row) {
            $title = is_array($row) ? mb_substr(trim((string) ($row['title'] ?? '')), 0, 255) : '';
            if ($title !== '') {
                $insert->execute(['level' => max(1, min(MAX_ITEM_LEVEL, (int) ($row['level'] ?? 1))), 'title' => $title, 'carry' => empty($row['carry']) ? 0 : 1, 'type' => itemTypeOrEmpty($row['type'] ?? ''), 'position' => ++$position]);
            }
        }
        $pdo->commit();
    } catch (Throwable $exception) {
        $pdo->rollBack();
        throw $exception;
    }
}

function parseEventRows(mixed $input): array
{
    $rows = [];
    foreach (is_array($input) ? $input : [] as $row) {
        if (!is_array($row)) {
            continue;
        }
        $event = [
            'id' => ctype_digit((string) ($row['id'] ?? '')) ? (int) $row['id'] : null,
            'name' => mb_substr(trim((string) ($row['name'] ?? '')), 0, 255),
            'event_date' => trim((string) ($row['date'] ?? '')),
            'event_time' => trim((string) ($row['time'] ?? '')),
            'patron' => mb_substr(trim((string) ($row['patron'] ?? '')), 0, 255),
            'notes' => trim((string) ($row['notes'] ?? '')),
        ];
        if ($event['name'] !== '' || $event['event_date'] !== '' || $event['event_time'] !== ''
            || $event['patron'] !== '' || $event['notes'] !== '') {
            $rows[] = $event;
        }
    }
    return $rows;
}

function validateEventRows(array $rows): ?string
{
    foreach ($rows as $event) {
        if ($event['name'] === '') {
            return 'U každé chystané akce vyplňte název.';
        }
        if (!isValidDate($event['event_date'])) {
            return 'U akce „' . $event['name'] . '“ zadejte platné datum.';
        }
        if ($event['event_time'] !== '' && !isValidTime($event['event_time'])) {
            return 'U akce „' . $event['name'] . '“ zkontrolujte čas.';
        }
    }
    return null;
}

function parseLoadedIds(mixed $input): array
{
    return array_map('intval', array_filter(explode(',', (string) $input), 'ctype_digit'));
}

function parsePastRows(mixed $input): array
{
    $rows = [];
    foreach (is_array($input) ? $input : [] as $row) {
        if (is_array($row) && ctype_digit((string) ($row['id'] ?? ''))) {
            $rows[] = ['id' => (int) $row['id'], 'evaluation' => trim((string) ($row['evaluation'] ?? ''))];
        }
    }
    return $rows;
}

/** Chystané akce k danému datu zápisu. */
function loadUpcomingEvents(PDO $pdo, string $date): array
{
    $statement = $pdo->prepare('SELECT * FROM events WHERE event_date >= :d ORDER BY event_date, event_time');
    $statement->execute(['d' => $date]);
    return $statement->fetchAll();
}

/** Akce, které proběhly před datem zápisu a ještě nebyly v žádném zápisu vyhodnoceny. */
function loadPendingPastEvents(PDO $pdo, string $date): array
{
    $statement = $pdo->prepare(
        'SELECT * FROM events WHERE event_date < :d AND evaluated_meeting_id IS NULL ORDER BY event_date, event_time'
    );
    $statement->execute(['d' => $date]);
    return $statement->fetchAll();
}

function loadMeetingEvents(PDO $pdo, int $meetingId, string $section): array
{
    $statement = $pdo->prepare(
        'SELECT e.* FROM meeting_events me JOIN events e ON e.id = me.event_id
         WHERE me.meeting_id = :m AND me.section = :s ORDER BY e.event_date, e.event_time'
    );
    $statement->execute(['m' => $meetingId, 's' => $section]);
    return $statement->fetchAll();
}

/** Doplní z databáze údaje o akcích podle odeslaných vyhodnocení (po chybě ve formuláři). */
function hydratePastRows(PDO $pdo, array $posted): array
{
    $rows = [];
    $statement = $pdo->prepare('SELECT * FROM events WHERE id = :id');
    foreach ($posted as $row) {
        $statement->execute(['id' => $row['id']]);
        $event = $statement->fetch();
        if ($event) {
            $event['evaluation'] = $row['evaluation'];
            $rows[] = $event;
        }
    }
    return $rows;
}

/** Volá se uvnitř transakce ukládání zápisu. */
function saveMeetingEvents(PDO $pdo, int $meetingId, array $upcoming, array $past, array $loadedIds = []): void
{
    $statement = $pdo->prepare("SELECT event_id FROM meeting_events WHERE meeting_id = :m AND section = 'upcoming'");
    $statement->execute(['m' => $meetingId]);
    $previousUpcoming = array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN));

    $pdo->prepare('DELETE FROM meeting_events WHERE meeting_id = :m')->execute(['m' => $meetingId]);
    $pdo->prepare('UPDATE events SET evaluated_meeting_id = NULL WHERE evaluated_meeting_id = :m')->execute(['m' => $meetingId]);

    $link = $pdo->prepare('INSERT IGNORE INTO meeting_events (meeting_id, event_id, section) VALUES (:m, :e, :s)');
    $kept = [];
    foreach ($upcoming as $event) {
        $params = [
            'name' => $event['name'],
            'date' => $event['event_date'],
            'time' => $event['event_time'] ?: null,
            'patron' => $event['patron'],
            'notes' => $event['notes'] ?: null,
        ];
        $id = $event['id'];
        if ($id) {
            $pdo->prepare('UPDATE events SET name = :name, event_date = :date, event_time = :time, patron = :patron, notes = :notes WHERE id = :id')
                ->execute($params + ['id' => $id]);
        } else {
            $pdo->prepare('INSERT INTO events (name, event_date, event_time, patron, notes) VALUES (:name, :date, :time, :patron, :notes)')
                ->execute($params);
            $id = (int) $pdo->lastInsertId();
        }
        $kept[] = $id;
        $link->execute(['m' => $meetingId, 'e' => $id, 's' => 'upcoming']);
    }

    foreach (array_diff(array_unique(array_merge($previousUpcoming, $loadedIds)), $kept) as $removed) {
        $pdo->prepare('DELETE FROM events WHERE id = :id')->execute(['id' => $removed]);
    }

    $evaluate = $pdo->prepare('UPDATE events SET evaluation = :evaluation, evaluated_meeting_id = :m WHERE id = :id');
    foreach ($past as $row) {
        $evaluate->execute(['evaluation' => $row['evaluation'] ?: null, 'm' => $meetingId, 'id' => $row['id']]);
        $link->execute(['m' => $meetingId, 'e' => $row['id'], 's' => 'past']);
    }
}

function eventTime(array $event): string
{
    return $event['event_time'] ? substr($event['event_time'], 0, 5) : '';
}

function renderEventRow(string $index, array $event): string
{
    $v = static fn (string $key): string => h((string) ($event[$key] ?? ''));
    $time = !empty($event['event_time']) ? h(substr((string) $event['event_time'], 0, 5)) : '';

    return '<div class="event-row">'
        . '<input type="hidden" data-field="id" name="events[' . h($index) . '][id]" value="' . $v('id') . '">'
        . '<div class="event-grid">'
        . '<label>Název <input type="text" data-field="name" name="events[' . h($index) . '][name]" maxlength="255" value="' . $v('name') . '"></label>'
        . '<label>Kdy <input type="date" data-field="date" name="events[' . h($index) . '][date]" value="' . $v('event_date') . '"></label>'
        . '<label>V kolik hodin <input type="time" data-field="time" name="events[' . h($index) . '][time]" value="' . $time . '"></label>'
        . '<label>Patron akce <input type="text" data-field="patron" name="events[' . h($index) . '][patron]" maxlength="255" value="' . $v('patron') . '"></label>'
        . '</div>'
        . '<label>Poznámky <textarea data-field="notes" name="events[' . h($index) . '][notes]" rows="2">' . $v('notes') . '</textarea></label>'
        . '<button type="button" class="event-remove" data-action="remove" title="Odebrat akci">✕</button>'
        . '</div>';
}

function renderPastEventRow(int $index, array $event): string
{
    $when = formatDate($event['event_date']) . (eventTime($event) !== '' ? ' v ' . eventTime($event) : '');
    return '<div class="event-row past">'
        . '<input type="hidden" name="past[' . $index . '][id]" value="' . (int) $event['id'] . '">'
        . '<p><strong>' . h($event['name']) . '</strong> – ' . h($when)
        . ($event['patron'] !== '' ? ' · patron: ' . h($event['patron']) : '') . '</p>'
        . ($event['notes'] ? '<p class="muted">' . nl2br(h($event['notes'])) . '</p>' : '')
        . '<label>Vyhodnocení (zpětná vazba)'
        . '<textarea name="past[' . $index . '][evaluation]" rows="3">' . h((string) ($event['evaluation'] ?? '')) . '</textarea></label>'
        . '</div>';
}

function renderUpcomingEventsTable(array $events): string
{
    if (!$events) {
        return '<p class="muted">Žádné chystané akce.</p>';
    }
    $html = '<table class="doc-table"><thead><tr><th>Název</th><th>Kdy</th><th>V kolik hodin</th><th>Patron akce</th><th>Poznámky</th></tr></thead><tbody>';
    foreach ($events as $event) {
        $html .= '<tr><td>' . h($event['name']) . '</td><td>' . h(formatDate($event['event_date'])) . '</td><td>'
            . h(eventTime($event)) . '</td><td>' . h($event['patron']) . '</td><td>' . nl2br(h((string) $event['notes'])) . '</td></tr>';
    }
    return $html . '</tbody></table>';
}

function renderPastEventsTable(array $events): string
{
    if (!$events) {
        return '<p class="muted">Žádné proběhlé akce k vyhodnocení.</p>';
    }
    $html = '<table class="doc-table"><thead><tr><th>Název</th><th>Kdy</th><th>Patron akce</th><th>Vyhodnocení (zpětná vazba)</th></tr></thead><tbody>';
    foreach ($events as $event) {
        $when = formatDate($event['event_date']) . (eventTime($event) !== '' ? ' ' . eventTime($event) : '');
        $html .= '<tr><td>' . h($event['name']) . '</td><td>' . h($when) . '</td><td>' . h($event['patron'])
            . '</td><td>' . nl2br(h((string) $event['evaluation'])) . '</td></tr>';
    }
    return $html . '</tbody></table>';
}
