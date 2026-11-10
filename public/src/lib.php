<?php

require_once __DIR__ . '/schedule.php';
require_once __DIR__ . '/calendar.php';
require_once __DIR__ . '/events.php';

const DEFAULT_AGENDA = [
    [1, 'Zprávy z VOJ'],
    [1, 'Družinové záležitosti'],
    [2, 'Vedoucí družin a družiny'],
    [2, 'Informace z oddílů'],
    [2, 'Výpravy'],
    [1, 'Roverský kmen'],
    [1, 'Prezentace střediska'],
    [1, 'Hospodaření'],
    [1, 'Dotace'],
    [1, 'Vzdělávání činovníků'],
    [2, 'Čekatelské zkoušky'],
    [2, 'Vůdcovky'],
    [2, 'Zdravoťák'],
    [1, 'Chata'],
    [2, 'Oprava a údržba'],
    [2, 'Úklid na chatě'],
    [2, 'Rezervace'],
    [1, 'Proběhlé akce'],
    [1, 'Nejbližší chystané akce'],
    [1, 'Různé'],
];

const MAX_ITEM_LEVEL = 5;

const CLOSING_TEXT = 'Díky všem za účast na radě, poznamenejte si prosím všechny akce do svých diářů (mobilů, facebooků atd.).';

function formatDate(?string $date): string
{
    return $date ? date('j. n. Y', strtotime($date)) : '';
}

function formatTime(?string $time): string
{
    return $time ? date('G:i', strtotime($time)) : '';
}

function inputTime(?string $time): string
{
    return $time ? substr($time, 0, 5) : '';
}

function isValidDate(string $value): bool
{
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    return $date && $date->format('Y-m-d') === $value;
}

function isValidTime(string $value): bool
{
    return (bool) preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $value);
}

// Skautský rok začíná v září.
function seasonFor(string $date): string
{
    $year = (int) substr($date, 0, 4);
    $month = (int) substr($date, 5, 2);
    $start = $month >= 9 ? $year : $year - 1;
    return $start . '/' . ($start + 1);
}

function nextMeetingNumber(PDO $pdo, string $season): int
{
    $statement = $pdo->prepare('SELECT COALESCE(MAX(meeting_number), 0) + 1 FROM meetings WHERE season = :season');
    $statement->execute(['season' => $season]);
    return (int) $statement->fetchColumn();
}

function linkify(string $escaped): string
{
    return preg_replace(
        '~(https?://[^\s<]+)~i',
        '<a href="$1" target="_blank" rel="noopener noreferrer">$1</a>',
        $escaped
    );
}

// Každý řádek je odrážka; odsazení (2 mezery) vytvoří podbod.
function renderNotes(?string $notes): string
{
    $html = '';
    $previous = -1;

    foreach (preg_split('/\R/u', (string) $notes) as $line) {
        if (trim($line) === '') {
            continue;
        }
        preg_match('/^([ \t]*)(?:[-•*]\s+)?(.*)$/u', $line, $match);
        $width = strlen(str_replace("\t", '  ', $match[1]));
        $depth = min(2, intdiv($width, 2), $previous + 1);

        if ($depth > $previous) {
            $html .= '<ul><li>';
        } else {
            $html .= str_repeat('</li></ul>', $previous - $depth) . '</li><li>';
        }
        $html .= linkify(h(trim($match[2])));
        $previous = $depth;
    }

    return $previous >= 0 ? $html . str_repeat('</li></ul>', $previous + 1) : '';
}

function itemKey(int $level, string $title): string
{
    return $level . '|' . mb_strtolower(trim($title));
}

function emptyItem(int $level, string $title): array
{
    return ['level' => $level, 'title' => $title, 'notes' => '', 'carry_over' => 0, 'carried' => 0];
}

/**
 * Základ nové osnovy je výchozí program; body označené v minulém zápisu
 * "řešit na další radě" z něj přebírají obsah, ostatní se přidají navíc.
 */
function buildAgenda(array $previousItems, ?array $template = null): array
{
    $items = [];
    $always = [];
    foreach ($template ?? DEFAULT_AGENDA as $row) {
        $item = emptyItem((int) $row[0], $row[1]);
        if (!empty($row[2])) {
            $item['carry_over'] = 1;
            $always[itemKey($item['level'], $item['title'])] = true;
        }
        $items[] = $item;
    }

    $pathOf = static function (array $list): array {
        $stack = [];
        $paths = [];
        foreach ($list as $index => $item) {
            $level = (int) $item['level'];
            $stack = array_slice($stack, 0, $level - 1);
            $stack[$level - 1] = mb_strtolower(trim($item['title']));
            $paths[$index] = implode('>', $stack);
        }
        return $paths;
    };

    $subtreeEnd = static function (array $list, int $index): int {
        $level = (int) $list[$index]['level'];
        $end = $index + 1;
        while ($end < count($list) && (int) $list[$end]['level'] > $level) {
            $end++;
        }
        return $end;
    };

    $stack = [];
    foreach ($previousItems as $previous) {
        $level = max(1, min(MAX_ITEM_LEVEL, (int) $previous['level']));
        $stack = array_slice($stack, 0, $level - 1);
        $stack[$level - 1] = $previous['title'];
        $isAlways = isset($always[itemKey($level, $previous['title'])]);
        if (!$previous['carry_over'] && !$isAlways) {
            continue;
        }

        $carried = [
            'level' => $level,
            'title' => $previous['title'],
            'notes' => (string) $previous['notes'],
            'carry_over' => $isAlways ? 1 : 0,
            'carried' => 1,
        ];

        $key = implode('>', array_map(static fn (string $s): string => mb_strtolower(trim($s)), $stack));
        $existing = array_search($key, $pathOf($items), true);
        if ($existing !== false) {
            $items[$existing] = $carried;
            continue;
        }

        // Chybějící nadřazené body se doplní a podbod se vloží na konec jejich větve.
        $insertAt = count($items);
        for ($depth = 1; $depth < $level; $depth++) {
            $ancestorKey = implode('>', array_map(
                static fn (string $s): string => mb_strtolower(trim($s)),
                array_slice($stack, 0, $depth)
            ));
            $ancestor = array_search($ancestorKey, $pathOf($items), true);
            if ($ancestor === false) {
                array_splice($items, $insertAt, 0, [emptyItem($depth, $stack[$depth - 1])]);
                $ancestor = $insertAt;
            }
            $insertAt = $subtreeEnd($items, $ancestor);
        }
        array_splice($items, $insertAt, 0, [$carried]);
    }

    return $items;
}

function numberedItems(array $items): array
{
    $result = [];
    $counters = array_fill(1, MAX_ITEM_LEVEL, 0);
    foreach ($items as $item) {
        $level = max(1, min(MAX_ITEM_LEVEL, (int) $item['level']));
        $counters[$level]++;
        for ($deeper = $level + 1; $deeper <= MAX_ITEM_LEVEL; $deeper++) {
            $counters[$deeper] = 0;
        }
        $n = $counters[$level];
        $item['label'] = match ($level) {
            1 => $n . '.',
            2 => chr(96 + min($n, 26)) . ')',
            3 => $n . ')',
            4 => '(' . chr(96 + min($n, 26)) . ')',
            default => '–',
        };
        $result[] = $item;
    }
    return $result;
}
function renderItemRow(string $index, array $item): string
{
    $level = (int) ($item['level'] ?? 1);
    $carry = !empty($item['carry_over']) ? ' checked' : '';
    $badge = !empty($item['carried'])
        ? '<span class="badge">Převzato z minulé rady</span>'
        : '';

    return '<div class="item-row" data-level="' . $level . '">'
        . '<div class="item-head">'
        . '<button type="button" class="collapse-toggle" data-action="toggle" title="Sbalit / rozbalit podbody">▾<span class="collapse-count"></span></button>'
        . '<input type="hidden" data-field="level" value="' . $level . '">'
        . '<input type="text" data-field="title" name="items[' . h($index) . '][title]" maxlength="255" placeholder="Název bodu" value="' . h($item['title'] ?? '') . '">'
        . '<div class="item-actions">'
        . '<button type="button" data-action="up" title="Posunout nahoru (i s podbody)">↑</button>'
        . '<button type="button" data-action="down" title="Posunout dolů (i s podbody)">↓</button>'
        . '<button type="button" data-action="outdent" title="Zrušit odsazení (bod)">⇤</button>'
        . '<button type="button" data-action="indent" title="Odsadit (podbod)">⇥</button>'
        . '<button type="button" data-action="remove" title="Odebrat">✕</button>'
        . '</div></div>'
        . '<textarea data-field="notes" name="items[' . h($index) . '][notes]" rows="4" placeholder="Poznámky – každý řádek je odrážka, odsazení dvěma mezerami vytvoří podbod">' . h($item['notes'] ?? '') . '</textarea>'
        . '<div class="item-foot"><label class="check"><input type="checkbox" data-field="carry" name="items[' . h($index) . '][carry]" value="1"' . $carry . '> Řešit na další radě</label>' . $badge . '</div>'
        . '</div>';
}

function renderResolutionRow(string $index, array $resolution): string
{
    $field = static fn (string $name): string => h(isset($resolution[$name]) ? (string) $resolution[$name] : '');

    return '<div class="resolution-row">'
        . '<textarea data-field="text" name="resolutions[' . h($index) . '][text]" rows="2" placeholder="Znění usnesení">' . $field('text') . '</textarea>'
        . '<div class="votes">'
        . '<label>Pro <input type="number" min="0" data-field="for" name="resolutions[' . h($index) . '][for]" value="' . $field('for') . '"></label>'
        . '<label>Proti <input type="number" min="0" data-field="against" name="resolutions[' . h($index) . '][against]" value="' . $field('against') . '"></label>'
        . '<label>Zdržel se <input type="number" min="0" data-field="abstain" name="resolutions[' . h($index) . '][abstain]" value="' . $field('abstain') . '"></label>'
        . '<button type="button" data-action="remove" title="Odebrat">✕</button>'
        . '</div></div>';
}

function validSignature(string $value): ?string
{
    return strlen($value) < 300000 && preg_match('~^data:image/png;base64,[A-Za-z0-9+/=]+$~', $value) ? $value : null;
}

function loadMeeting(PDO $pdo, int $id): ?array
{
    $statement = $pdo->prepare('SELECT * FROM meetings WHERE id = :id');
    $statement->execute(['id' => $id]);
    $meeting = $statement->fetch();
    if (!$meeting) {
        return null;
    }

    $statement = $pdo->prepare('SELECT * FROM meeting_items WHERE meeting_id = :id ORDER BY position');
    $statement->execute(['id' => $id]);
    $meeting['items'] = $statement->fetchAll();

    $statement = $pdo->prepare('SELECT * FROM meeting_resolutions WHERE meeting_id = :id ORDER BY position');
    $statement->execute(['id' => $id]);
    $meeting['resolutions'] = $statement->fetchAll();

    try {
        $meeting['events_upcoming'] = loadMeetingEvents($pdo, $id, 'upcoming');
        $meeting['events_past'] = loadMeetingEvents($pdo, $id, 'past');
    } catch (Throwable $exception) {
        $meeting['events_upcoming'] = $meeting['events_past'] = [];
    }

    return $meeting;
}

function saveMeeting(PDO $pdo, ?int $id, array $v, array $items, array $resolutions, array $upcomingEvents = [], array $pastEvents = [], array $loadedEventIds = []): int
{
    $params = [
        'meeting_number' => (int) $v['meeting_number'],
        'season' => seasonFor($v['meeting_date']),
        'meeting_date' => $v['meeting_date'],
        'time_from' => $v['time_from'] ?: null,
        'time_to' => $v['time_to'] ?: null,
        'place' => $v['place'],
        'attendees' => $v['attendees'] ?: null,
        'online_attendees' => $v['online_attendees'] ?: null,
        'absentees' => $v['absentees'] ?: null,
        'next_meeting_date' => $v['next_meeting_date'] ?: null,
        'next_meeting_time' => $v['next_meeting_time'] ?: null,
        'author' => $v['author'] ?: null,
        'verifier' => $v['verifier'] ?: null,
        'author_signature' => validSignature($v['author_signature'] ?? ''),
        'verifier_signature' => validSignature($v['verifier_signature'] ?? ''),
        'calendar_event_key' => ($v['calendar_event_key'] ?? '') ?: null,
    ];

    $pdo->beginTransaction();
    try {
        if ($id === null) {
            $pdo->prepare(
                'INSERT INTO meetings (meeting_number, season, meeting_date, time_from, time_to, place, attendees,
                    online_attendees, absentees, next_meeting_date, next_meeting_time, author, verifier, author_signature, verifier_signature, calendar_event_key)
                 VALUES (:meeting_number, :season, :meeting_date, :time_from, :time_to, :place, :attendees,
                    :online_attendees, :absentees, :next_meeting_date, :next_meeting_time, :author, :verifier, :author_signature, :verifier_signature, :calendar_event_key)'
            )->execute($params);
            $id = (int) $pdo->lastInsertId();
        } else {
            $params['id'] = $id;
            $pdo->prepare(
                'UPDATE meetings SET meeting_number = :meeting_number, season = :season, meeting_date = :meeting_date,
                    time_from = :time_from, time_to = :time_to, place = :place, attendees = :attendees,
                    online_attendees = :online_attendees, absentees = :absentees,
                    next_meeting_date = :next_meeting_date, next_meeting_time = :next_meeting_time,
                    author = :author, verifier = :verifier, author_signature = :author_signature, verifier_signature = :verifier_signature, calendar_event_key = :calendar_event_key                 WHERE id = :id'
            )->execute($params);
            $pdo->prepare('DELETE FROM meeting_items WHERE meeting_id = :id')->execute(['id' => $id]);
            $pdo->prepare('DELETE FROM meeting_resolutions WHERE meeting_id = :id')->execute(['id' => $id]);
        }

        $insertItem = $pdo->prepare(
            'INSERT INTO meeting_items (meeting_id, position, level, title, notes, carry_over)
             VALUES (:meeting_id, :position, :level, :title, :notes, :carry_over)'
        );
        foreach ($items as $position => $item) {
            $insertItem->execute([
                'meeting_id' => $id,
                'position' => $position,
                'level' => $item['level'],
                'title' => $item['title'],
                'notes' => $item['notes'] ?: null,
                'carry_over' => $item['carry_over'],
            ]);
        }

        $insertResolution = $pdo->prepare(
            'INSERT INTO meeting_resolutions (meeting_id, position, text, votes_for, votes_against, votes_abstain)
             VALUES (:meeting_id, :position, :text, :votes_for, :votes_against, :votes_abstain)'
        );
        foreach ($resolutions as $position => $resolution) {
            $insertResolution->execute([
                'meeting_id' => $id,
                'position' => $position,
                'text' => $resolution['text'],
                'votes_for' => $resolution['for'],
                'votes_against' => $resolution['against'],
                'votes_abstain' => $resolution['abstain'],
            ]);
        }

        saveMeetingEvents($pdo, $id, $upcomingEvents, $pastEvents, $loadedEventIds);

        $pdo->commit();
    } catch (Throwable $exception) {
        $pdo->rollBack();
        throw $exception;
    }

    return $id;
}

function parseItems(mixed $input): array
{
    $items = [];
    foreach (is_array($input) ? $input : [] as $row) {
        if (!is_array($row)) {
            continue;
        }
        $title = trim((string) ($row['title'] ?? ''));
        if ($title === '') {
            continue;
        }
        $items[] = [
            'level' => max(1, min(MAX_ITEM_LEVEL, (int) ($row['level'] ?? 1))),
            'title' => mb_substr($title, 0, 255),
            'notes' => trim(str_replace("\r\n", "\n", (string) ($row['notes'] ?? ''))),
            'carry_over' => !empty($row['carry']) ? 1 : 0,
        ];
    }
    return $items;
}

function parseResolutions(mixed $input): array
{
    $count = static fn (mixed $value): ?int => ($value === null || trim((string) $value) === '' || !ctype_digit(trim((string) $value)))
        ? null
        : (int) trim((string) $value);

    $resolutions = [];
    foreach (is_array($input) ? $input : [] as $row) {
        if (!is_array($row)) {
            continue;
        }
        $text = trim((string) ($row['text'] ?? ''));
        if ($text === '') {
            continue;
        }
        $resolutions[] = [
            'text' => $text,
            'for' => $count($row['for'] ?? null),
            'against' => $count($row['against'] ?? null),
            'abstain' => $count($row['abstain'] ?? null),
        ];
    }
    return $resolutions;
}
