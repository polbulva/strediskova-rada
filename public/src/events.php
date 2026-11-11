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
            'notes' => sanitizeNotesHtml((string) ($row['notes'] ?? '')),
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
            return 'U každé chystané akce vyplňte název akce.';
        }
        if (!isValidDate($event['event_date'])) {
            return 'U akce „' . $event['name'] . '“ zadejte platný termín.';
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
        if (!is_array($row)) {
            continue;
        }
        $event = [
            'id' => ctype_digit((string) ($row['id'] ?? '')) ? (int) $row['id'] : null,
            'name' => mb_substr(trim((string) ($row['name'] ?? '')), 0, 255),
            'event_date' => trim((string) ($row['date'] ?? '')),
            'event_time' => trim((string) ($row['time'] ?? '')),
            'patron' => mb_substr(trim((string) ($row['patron'] ?? '')), 0, 255),
            'evaluation' => sanitizeNotesHtml((string) ($row['evaluation'] ?? '')),
        ];
        if ($event['id'] !== null || $event['name'] !== '' || $event['event_date'] !== '' || $event['patron'] !== '' || $event['evaluation'] !== '') {
            $rows[] = $event;
        }
    }
    return $rows;
}

function validatePastRows(array $rows): ?string
{
    foreach ($rows as $event) {
        if ($event['name'] === '') {
            return 'U každé proběhlé akce vyplňte název akce.';
        }
        if (!isValidDate($event['event_date'])) {
            return 'U proběhlé akce „' . $event['name'] . '“ zadejte platný termín.';
        }
        if ($event['event_time'] !== '' && !isValidTime($event['event_time'])) {
            return 'U proběhlé akce „' . $event['name'] . '“ zkontrolujte čas.';
        }
    }
    return null;
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
    $events = $statement->fetchAll();
    foreach ($events as &$event) {
        if (trim((string) $event['evaluation']) === '') {
            $event['evaluation'] = $event['notes'];
        }
    }
    unset($event);
    return $events;
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

    foreach ($past as $row) {
        $params = [
            'name' => $row['name'],
            'date' => $row['event_date'],
            'time' => $row['event_time'] ?: null,
            'patron' => $row['patron'],
            'evaluation' => $row['evaluation'] ?: null,
            'm' => $meetingId,
        ];
        $id = $row['id'];
        if ($id) {
            $pdo->prepare('UPDATE events SET name = :name, event_date = :date, event_time = :time, patron = :patron, evaluation = :evaluation, evaluated_meeting_id = :m WHERE id = :id')
                ->execute($params + ['id' => $id]);
        } else {
            $pdo->prepare('INSERT INTO events (name, event_date, event_time, patron, evaluation, evaluated_meeting_id) VALUES (:name, :date, :time, :patron, :evaluation, :m)')
                ->execute($params);
            $id = (int) $pdo->lastInsertId();
        }
        $link->execute(['m' => $meetingId, 'e' => $id, 's' => 'past']);
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
        . '<label>Název akce <input type="text" data-field="name" name="events[' . h($index) . '][name]" maxlength="255" value="' . $v('name') . '"></label>'
        . '<label>Termín <input type="date" data-field="date" name="events[' . h($index) . '][date]" value="' . $v('event_date') . '"></label>'
        . '<label>Čas (nepovinně) <input type="time" data-field="time" name="events[' . h($index) . '][time]" value="' . $time . '"></label>'
        . '<label>Garant <input type="text" data-field="patron" name="events[' . h($index) . '][patron]" maxlength="255" value="' . $v('patron') . '"></label>'
        . '</div>'
        . '<div class="event-summary"><span>Shrnutí</span>' . renderRichEditor((string) ($event['notes'] ?? ''), 'notes', 'events[' . $index . '][notes]') . '</div>'
        . '<button type="button" class="event-remove" data-action="remove" title="Odebrat akci">' . icon('close') . '</button>'
        . '</div>';
}

function renderPastEventRow(string $index, array $event): string
{
    $v = static fn (string $key): string => h((string) ($event[$key] ?? ''));
    $time = !empty($event['event_time']) ? h(substr((string) $event['event_time'], 0, 5)) : '';
    $name = static fn (string $field): string => 'name="past[' . h($index) . '][' . $field . ']" data-field="' . $field . '"';

    return '<div class="event-row past">'
        . '<input type="hidden" ' . $name('id') . ' value="' . $v('id') . '">'
        . '<div class="event-grid">'
        . '<label>Název akce <input type="text" ' . $name('name') . ' maxlength="255" value="' . $v('name') . '"></label>'
        . '<label>Termín <input type="date" ' . $name('date') . ' value="' . $v('event_date') . '"></label>'
        . '<label>Čas (nepovinně) <input type="time" ' . $name('time') . ' value="' . $time . '"></label>'
        . '<label>Garant <input type="text" ' . $name('patron') . ' maxlength="255" value="' . $v('patron') . '"></label>'
        . '</div>'
        . '<div class="event-summary"><span>Shrnutí / vyhodnocení</span>' . renderRichEditor((string) ($event['evaluation'] ?? ''), 'evaluation', 'past[' . $index . '][evaluation]') . '</div>'
        . '<button type="button" class="event-remove" data-action="remove" title="Odebrat akci">' . icon('close') . '</button>'
        . '</div>';
}

function renderPastEventsEditor(array $events): string
{
    $rows = '';
    foreach (array_values($events) as $index => $event) {
        $rows .= renderPastEventRow((string) $index, $event);
    }
    return '<div class="events-editor"><p class="muted">Akce, které se konaly od minulé rady. Po uložení se v dalších zápisech už nezobrazí.</p>'
        . '<div id="past" data-name="past">' . $rows . '</div>'
        . '<button class="button secondary" type="button" data-add="past">+ Přidat proběhlou akci</button></div>';
}

function renderUpcomingEventsEditor(array $events, array $loadedIds): string
{
    $rows = '';
    foreach (array_values($events) as $index => $event) {
        $rows .= renderEventRow((string) $index, $event);
    }
    return '<div class="events-editor"><input type="hidden" name="events_loaded" value="' . h(implode(',', $loadedIds)) . '">'
        . '<div id="events" data-name="events">' . $rows . '</div>'
        . '<button class="button secondary" type="button" data-add="events">+ Přidat chystanou akci</button></div>';
}
function renderEventBlocks(array $events, string $summaryField, string $empty): string
{
    if (!$events) {
        return '<p class="muted">' . h($empty) . '</p>';
    }
    $html = '';
    foreach ($events as $event) {
        $when = formatDate($event['event_date']) . (eventTime($event) !== '' ? ' v ' . eventTime($event) : '');
        $html .= '<div class="doc-event"><p class="doc-event-head"><strong>' . h($event['name']) . '</strong> – ' . h($when)
            . ($event['patron'] !== '' ? ' · garant: ' . h($event['patron']) : '') . '</p>'
            . renderNotes((string) ($event[$summaryField] ?? '')) . '</div>';
    }
    return $html;
}

function renderUpcomingEventsTable(array $events): string
{
    return renderEventBlocks($events, 'notes', 'Žádné chystané akce.');
}

function renderPastEventsTable(array $events): string
{
    return renderEventBlocks($events, 'evaluation', 'Žádné proběhlé akce k vyhodnocení.');
}