<?php

require __DIR__ . '/src/bootstrap.php';
require __DIR__ . '/src/lib.php';

$currentUser = requireModule('minutes');

$fields = [
    'meeting_number', 'meeting_date', 'time_from', 'time_to', 'place', 'attendees', 'online_attendees',
    'absentees', 'next_meeting_date', 'next_meeting_time', 'author', 'verifier', 'calendar_event_key',
];
$values = array_fill_keys($fields, '');
$values['meeting_date'] = date('Y-m-d');
$items = [];
$resolutions = [];
$upcomingEvents = [];
$pastEvents = [];
$loadedEventIds = [];
$errors = [];
$notice = '';
$databaseError = '';
$pdo = null;
$meeting = null;
$editId = null;
$previous = null;
$allMeetings = [];
$linkedKeys = [];
$calendarEvents = [];
$allCalendarEvents = [];
$calendarError = '';
$event = null;
try {
    $pdo = databaseConnection();
} catch (Throwable $exception) {
    $databaseError = 'Databázi se nepodařilo připojit. Zkontrolujte konfiguraci a postup v README.';
}

$tokenValid = static fn (): bool => csrfValid();

if ($pdo && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (($_POST['action'] ?? '') === 'delete') {
        if ($tokenValid() && ctype_digit((string) ($_POST['id'] ?? ''))) {
            try {
                $pdo->prepare('DELETE FROM meetings WHERE id = :id')->execute(['id' => (int) $_POST['id']]);
                header('Location: minutes.php?deleted=1');
                exit;
            } catch (Throwable $exception) {
                $errors[] = 'Zápis se nepodařilo smazat.';
            }
        } else {
            $errors[] = 'Formulář vypršel nebo není platný. Obnovte stránku a zkuste to znovu.';
        }
    } else {
        foreach ($fields as $field) {
            $values[$field] = trim((string) ($_POST[$field] ?? ''));
        }
        $items = parseItems($_POST['items'] ?? []);
        $resolutions = parseResolutions($_POST['resolutions'] ?? []);
        $upcomingEvents = parseEventRows($_POST['events'] ?? []);
        $postedPast = parsePastRows($_POST['past'] ?? []);
        $loadedEventIds = parseLoadedIds($_POST['events_loaded'] ?? '');
        $editId = ctype_digit((string) ($_POST['id'] ?? '')) ? (int) $_POST['id'] : null;

        if (!$tokenValid()) {
            $errors[] = 'Formulář vypršel nebo není platný. Obnovte stránku a zkuste to znovu.';
        }
        if ($values['place'] === '' || $values['meeting_date'] === '' || !ctype_digit($values['meeting_number'])) {
            $errors[] = 'Vyplňte číslo rady, datum a místo konání.';
        }
        foreach (['meeting_date', 'next_meeting_date'] as $dateField) {
            if ($values[$dateField] !== '' && !isValidDate($values[$dateField])) {
                $errors[] = 'Zkontrolujte zadané datum.';
                break;
            }
        }
        foreach (['time_from', 'time_to', 'next_meeting_time'] as $timeField) {
            if ($values[$timeField] !== '' && !isValidTime($values[$timeField])) {
                $errors[] = 'Zkontrolujte zadaný čas.';
                break;
            }
        }

        if ($eventError = validateEventRows($upcomingEvents)) {
            $errors[] = $eventError;
        }

        if (!$errors) {
            try {
                $id = saveMeeting($pdo, $editId, $values, $items, $resolutions, $upcomingEvents, $postedPast, $loadedEventIds);
                header('Location: minutes.php?id=' . $id . '&saved=1');
                exit;
            } catch (Throwable $exception) {
                $errors[] = $exception instanceof PDOException && $exception->getCode() === '23000'
                    ? 'K této události z kalendáře už zápis existuje.'
                    : 'Zápis se nepodařilo uložit. Zkontrolujte, že je databáze připravena podle skriptů v database/migrations.';
            }
        }
    }
}

$isPost = $_SERVER['REQUEST_METHOD'] === 'POST';

if ($isPost && $pdo && isset($postedPast)) {
    try {
        $pastEvents = hydratePastRows($pdo, $postedPast);
    } catch (Throwable $exception) {
        $pastEvents = [];
    }
}

if ($pdo && !$databaseError) {
    try {
        $allMeetings = $pdo->query(
            'SELECT id, meeting_number, season, meeting_date, place FROM meetings
             ORDER BY meeting_date DESC, id DESC LIMIT 50'
        )->fetchAll();

        $linkedKeys = $pdo->query('SELECT calendar_event_key, id FROM meetings WHERE calendar_event_key IS NOT NULL')
            ->fetchAll(PDO::FETCH_KEY_PAIR);

        if (calendarConfigured()) {
            try {
                $calendarEvents = calendarEvents(isset($_GET['refresh']));
                $allCalendarEvents = $calendarEvents;
                if (!isset($_GET['allcal'])) {
                    $today = (new DateTimeImmutable('now', calendarTimezone()))->format('Y-m-d');
                    $past = $current = $future = [];
                    foreach ($calendarEvents as $e) {
                        $day = $e['start']->format('Y-m-d');
                        if ($day < $today) {
                            $past = [$e];
                        } elseif ($day === $today) {
                            $current[] = $e;
                        } elseif (!$future) {
                            $future = [$e];
                        }
                    }
                    $calendarEvents = array_merge($past, $current, $future);
                }
            } catch (Throwable $exception) {
                $calendarError = 'Kalendář se nepodařilo načíst: ' . $exception->getMessage();
            }
        }

        $eventKey = (string) ($_GET['event'] ?? '');
        if (!$isPost && $eventKey !== '' && !isset($_GET['id']) && !isset($_GET['edit'])) {
            if (isset($linkedKeys[$eventKey])) {
                header('Location: minutes.php?id=' . (int) $linkedKeys[$eventKey]);
                exit;
            }
            foreach ($allCalendarEvents as $candidate) {
                if ($candidate['key'] === $eventKey) {
                    $event = $candidate;
                    break;
                }
            }
            if (!$event) {
                $errors[] = 'Událost v kalendáři nebyla nalezena. Zkuste kalendář načíst znovu.';
            }
        }

        if (!$isPost && isset($_GET['id']) && ctype_digit((string) $_GET['id'])) {
            $meeting = loadMeeting($pdo, (int) $_GET['id']);
        } elseif (!$isPost && isset($_GET['edit']) && ctype_digit((string) $_GET['edit'])) {
            $existing = loadMeeting($pdo, (int) $_GET['edit']);
            if ($existing) {
                $editId = (int) $existing['id'];
                foreach ($fields as $field) {
                    $values[$field] = (string) ($existing[$field] ?? '');
                }
                foreach (['time_from', 'time_to', 'next_meeting_time'] as $timeField) {
                    $values[$timeField] = inputTime($existing[$timeField]);
                }
                foreach ($existing['items'] as $row) {
                    $items[] = $row;
                }
                $upcomingEvents = $existing['events_upcoming'];
                $pastEvents = $existing['events_past'];
                $loadedEventIds = array_map(static fn (array $e): int => (int) $e['id'], $upcomingEvents);
                foreach ($existing['resolutions'] as $row) {
                    $resolutions[] = [
                        'text' => $row['text'],
                        'for' => $row['votes_for'],
                        'against' => $row['votes_against'],
                        'abstain' => $row['votes_abstain'],
                    ];
                }
            }
        } elseif (!$isPost) {
            $fromId = $allMeetings[0]['id'] ?? null;
            if (isset($_GET['from'])) {
                $fromId = ctype_digit((string) $_GET['from']) ? (int) $_GET['from'] : null;
            } elseif ($event) {
                $statement = $pdo->prepare('SELECT id FROM meetings WHERE meeting_date < :d ORDER BY meeting_date DESC, id DESC LIMIT 1');
                $statement->execute(['d' => $event['start']->format('Y-m-d')]);
                $fromId = $statement->fetchColumn() ?: null;
            }
            $previous = $fromId ? loadMeeting($pdo, (int) $fromId) : null;

            $season = seasonFor($values['meeting_date']);
            $values['meeting_number'] = (string) nextMeetingNumber($pdo, $season);
            if ($previous) {
                $values['place'] = $previous['place'];
                $values['time_from'] = inputTime($previous['time_from']);
                $values['time_to'] = inputTime($previous['time_to']);
                $values['author'] = (string) $previous['author'];
                $values['verifier'] = (string) $previous['verifier'];
                if ($previous['next_meeting_date']) {
                    $values['meeting_date'] = $previous['next_meeting_date'];
                    $values['time_from'] = inputTime($previous['next_meeting_time']) ?: $values['time_from'];
                    $values['time_to'] = '';
                    $season = seasonFor($values['meeting_date']);
                    $values['meeting_number'] = (string) nextMeetingNumber($pdo, $season);
                }
            }
            $items = buildAgenda($previous['items'] ?? [], loadAgendaTemplate($pdo));

            if ($event) {
                $values['calendar_event_key'] = $event['key'];
                $values['meeting_date'] = $event['start']->format('Y-m-d');
                if (!$event['all_day']) {
                    $values['time_from'] = $event['start']->format('H:i');
                    $values['time_to'] = $event['end'] ? $event['end']->format('H:i') : '';
                }
                if ($event['location'] !== '') {
                    $values['place'] = $event['location'];
                }
                $values['meeting_number'] = (string) nextMeetingNumber($pdo, seasonFor($values['meeting_date']));
            }

            $upcomingEvents = loadUpcomingEvents($pdo, $values['meeting_date']);
            $pastEvents = loadPendingPastEvents($pdo, $values['meeting_date']);
            $loadedEventIds = array_map(static fn (array $e): int => (int) $e['id'], $upcomingEvents);
        }
    } catch (Throwable $exception) {
        $databaseError = 'Zápisy nelze načíst. Zkontrolujte, že je databáze připravena podle skriptů v database/migrations.';
    }
}

if (isset($_GET['saved'])) {
    $notice = 'Zápis byl uložen.';
} elseif (isset($_GET['deleted'])) {
    $notice = 'Zápis byl smazán.';
}

$showForm = !$meeting && $pdo && !$databaseError;
renderHeader('Zápisy z rady', $currentUser);
?>
<header class="site-header">
    <div class="container">
        <p class="eyebrow"><a class="back-link" href="portal.php">← Rozcestník</a></p>
        <h1>Zápisy z jednání</h1>
        <p>Vytvořte, uložte a vytiskněte zápis z rady.</p>
    </div>
</header>

<main class="container">
    <?php renderFlash(); ?>
    <?php if ($notice): ?>
        <p class="message success"><?= h($notice) ?></p>
    <?php endif; ?>
    <?php if ($databaseError): ?>
        <p class="message error"><?= h($databaseError) ?></p>
    <?php endif; ?>
    <?php foreach ($errors as $error): ?>
        <p class="message error"><?= h($error) ?></p>
    <?php endforeach; ?>

    <?php if ($meeting): ?>
        <?php
        $numbered = numberedItems($meeting['items']);
        $chapters = count(array_filter($numbered, static fn (array $item): bool => (int) $item['level'] === 1));
        ?>
        <article class="card minutes">
            <div class="minutes-toolbar">
                <a class="button secondary" href="minutes.php">← Zpět</a>
                <a class="button secondary" href="minutes.php?edit=<?= (int) $meeting['id'] ?>">Upravit</a>
                <button class="button secondary" type="button" onclick="window.print()">Tisknout</button>
                <form method="post" onsubmit="return confirm('Opravdu smazat tento zápis?');">
                    <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id" value="<?= (int) $meeting['id'] ?>">
                    <button class="button danger" type="submit">Smazat</button>
                </form>
            </div>

            <?php if ($logo = logoUrl()): ?><img class="doc-logo" src="<?= h($logo) ?>" alt="Logo střediska"><?php endif; ?>
            <h2 class="doc-title">Zápis ze střediskové rady č. <?= (int) $meeting['meeting_number'] ?> rok <?= h($meeting['season']) ?></h2>
            <p class="doc-meta">
                <span><strong>Místo:</strong> <?= h($meeting['place']) ?></span>
                <span><strong>Datum konání:</strong> <?= h(formatDate($meeting['meeting_date'])) ?></span>
                <?php if ($meeting['time_from']): ?>
                    <span><strong>Čas konání:</strong> <?= h(formatTime($meeting['time_from'])) ?><?= $meeting['time_to'] ? ' – ' . h(formatTime($meeting['time_to'])) : '' ?></span>
                <?php endif; ?>
            </p>

            <h3>Docházka</h3>
            <p><strong>Přítomni:</strong> <?= h($meeting['attendees']) ?></p>
            <?php if ($meeting['online_attendees']): ?><p><strong>Online:</strong> <?= h($meeting['online_attendees']) ?></p><?php endif; ?>
            <p><strong>Omluveni:</strong> <?= h($meeting['absentees']) ?></p>

            <?php if ($numbered || $meeting['resolutions']): ?>
                <h3>Osnova rady:</h3>
                <ol class="toc">
                    <?php foreach ($numbered as $item): ?>
                        <li class="level-<?= (int) $item['level'] ?>"><?= h($item['label']) ?> <?= h($item['title']) ?></li>
                    <?php endforeach; ?>
                    <?php if ($meeting['resolutions']): ?>
                        <li class="level-1"><?= $chapters + 1 ?>. Usnesení</li>
                    <?php endif; ?>
                </ol>
            <?php endif; ?>

            <?php foreach ($numbered as $item): ?>
                <section class="doc-item level-<?= (int) $item['level'] ?>">
                    <?php if ((int) $item['level'] === 1): ?>
                        <h3><?= h($item['label']) ?> <?= h($item['title']) ?></h3>
                    <?php else: ?>
                        <h4><?= h($item['label']) ?> <?= h($item['title']) ?></h4>
                    <?php endif; ?>
                    <?= renderNotes($item['notes']) ?>
                    <?php if ((int) $item['level'] === 2 && mb_strtolower(trim($item['title'])) === 'vedoucí družin a družiny'): ?>
                        <?= renderSeasonTimetables($meeting['season']) ?>
                    <?php endif; ?>
                    <?php $titleLower = mb_strtolower($item['title']); ?>
                    <?php if (str_contains($titleLower, 'proběhlé akce')): ?>
                        <?= renderPastEventsTable($meeting['events_past']) ?>
                    <?php elseif (str_contains($titleLower, 'chystané akce')): ?>
                        <?= renderUpcomingEventsTable($meeting['events_upcoming']) ?>
                    <?php endif; ?>
                    <?php if ($item['carry_over']): ?><p class="carry-tag">↻ Řešit na další radě</p><?php endif; ?>
                </section>
            <?php endforeach; ?>

            <?php if ($meeting['resolutions']): ?>
                <section class="doc-item level-1">
                    <h3><?= $chapters + 1 ?>. Usnesení</h3>
                    <?php foreach ($meeting['resolutions'] as $resolution): ?>
                        <div class="resolution">
                            <p><?= nl2br(h($resolution['text'])) ?></p>
                            <p class="votes-line">
                                Pro: <?= h((string) $resolution['votes_for']) ?>
                                Proti: <?= h((string) $resolution['votes_against']) ?>
                                Zdržel se: <?= h((string) $resolution['votes_abstain']) ?>
                            </p>
                        </div>
                    <?php endforeach; ?>
                </section>
            <?php endif; ?>

            <footer class="doc-footer">
                <?php if ($meeting['next_meeting_date']): ?>
                    <p><strong>Další rada:</strong> <?= h(formatDate($meeting['next_meeting_date'])) ?><?= $meeting['next_meeting_time'] ? ' v ' . h(formatTime($meeting['next_meeting_time'])) : '' ?></p>
                <?php endif; ?>
                <p><?= h(CLOSING_TEXT) ?></p>
                <p class="signatures">
                    <span><strong>Zapsal:</strong> <?= h($meeting['author']) ?></span>
                    <span><strong>Ověřil:</strong> <?= h($meeting['verifier']) ?></span>
                </p>
            </footer>
        </article>
    <?php endif; ?>

    <section class="layout<?= $showForm ? '' : ' single' ?>">
        <?php if ($showForm): ?>
            <section class="card form-card">
                <h2><?= $editId ? 'Úprava zápisu' : 'Nový zápis' ?></h2>

                <?php if (!$editId && !$isPost): ?>
                    <form method="get" class="carry-form">
                        <?php if ($event): ?><input type="hidden" name="event" value="<?= h($event['key']) ?>"><?php endif; ?>
                        <label>Převzít body z minulého zápisu
                            <select name="from" onchange="this.form.submit()">
                                <option value="none">– nepřebírat nic –</option>
                                <?php foreach ($allMeetings as $item): ?>
                                    <option value="<?= (int) $item['id'] ?>"<?= $previous && (int) $previous['id'] === (int) $item['id'] ? ' selected' : '' ?>>
                                        č. <?= (int) $item['meeting_number'] ?> (<?= h($item['season']) ?>) – <?= h(formatDate($item['meeting_date'])) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <p class="muted">Z vybraného zápisu se převezmou body označené „Řešit na další radě“ včetně poznámek, které můžete upravit. Změnou výběru se nevyplněný formulář načte znovu.</p>
                    </form>
                <?php endif; ?>

                <form method="post" id="minutes-form">
                    <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
                    <input type="hidden" name="id" value="<?= $editId ? (int) $editId : '' ?>">
                    <input type="hidden" name="calendar_event_key" value="<?= h($values['calendar_event_key']) ?>">
                    <?php if ($values['calendar_event_key'] !== ''): ?>
                        <p class="muted">📅 Zápis je navázán na událost v kalendáři.</p>
                    <?php endif; ?>
                    <p class="muted">Rozvrh schůzek družin se do zápisu doplní automaticky podle sezóny. <?php if (canAccess($currentUser, 'schedule_edit')): ?><a href="schedule.php">Upravit rozvrh</a><?php endif; ?></p>
                    <div class="form-grid">
                        <label>Číslo rady <span class="required">*</span>
                            <input type="number" min="1" name="meeting_number" required value="<?= h($values['meeting_number']) ?>">
                        </label>
                        <label>Datum konání <span class="required">*</span>
                            <input type="date" name="meeting_date" required value="<?= h($values['meeting_date']) ?>">
                        </label>
                        <label>Místo <span class="required">*</span>
                            <input type="text" name="place" required maxlength="255" value="<?= h($values['place']) ?>">
                        </label>
                        <div class="time-pair">
                            <label>Čas od
                                <input type="time" name="time_from" value="<?= h($values['time_from']) ?>">
                            </label>
                            <label>Čas do
                                <input type="time" name="time_to" value="<?= h($values['time_to']) ?>">
                            </label>
                        </div>
                        <label class="wide">Přítomni
                            <textarea name="attendees" rows="2"><?= h($values['attendees']) ?></textarea>
                        </label>
                        <label>Online
                            <textarea name="online_attendees" rows="2"><?= h($values['online_attendees']) ?></textarea>
                        </label>
                        <label>Omluveni
                            <textarea name="absentees" rows="2"><?= h($values['absentees']) ?></textarea>
                        </label>
                    </div>

                    <h3>Osnova a poznámky</h3>
                    <div id="items" data-name="items">
                        <?php foreach (array_values($items) as $index => $item): ?>
                            <?= renderItemRow((string) $index, $item) ?>
                        <?php endforeach; ?>
                    </div>
                    <button class="button secondary" type="button" data-add="items">+ Přidat bod</button>

                    <h3>Proběhlé akce – vyhodnocení</h3>
                    <p class="muted">Akce, které se konaly od minulé rady. Po uložení se v dalších zápisech už nezobrazí.</p>
                    <?php foreach (array_values($pastEvents) as $index => $pastEvent): ?>
                        <?= renderPastEventRow($index, $pastEvent) ?>
                    <?php endforeach; ?>
                    <?php if (!$pastEvents): ?><p class="muted">Žádné proběhlé akce k vyhodnocení.</p><?php endif; ?>

                    <h3>Nejbližší chystané akce</h3>
                    <input type="hidden" name="events_loaded" value="<?= h(implode(',', $loadedEventIds)) ?>">
                    <div id="events" data-name="events">
                        <?php foreach (array_values($upcomingEvents) as $index => $upcomingEvent): ?>
                            <?= renderEventRow((string) $index, $upcomingEvent) ?>
                        <?php endforeach; ?>
                    </div>
                    <button class="button secondary" type="button" data-add="events">+ Přidat akci</button>

                    <h3>Usnesení</h3>
                    <div id="resolutions" data-name="resolutions">
                        <?php foreach (array_values($resolutions) as $index => $resolution): ?>
                            <?= renderResolutionRow((string) $index, $resolution) ?>
                        <?php endforeach; ?>
                    </div>
                    <button class="button secondary" type="button" data-add="resolutions">+ Přidat usnesení</button>

                    <h3>Závěr</h3>
                    <div class="form-grid">
                        <label>Další rada – datum
                            <input type="date" name="next_meeting_date" value="<?= h($values['next_meeting_date']) ?>">
                        </label>
                        <label>Další rada – čas
                            <input type="time" name="next_meeting_time" value="<?= h($values['next_meeting_time']) ?>">
                        </label>
                        <label>Zapsal
                            <input type="text" name="author" maxlength="255" value="<?= h($values['author']) ?>">
                        </label>
                        <label>Ověřil
                            <input type="text" name="verifier" maxlength="255" value="<?= h($values['verifier']) ?>">
                        </label>
                    </div>

                    <button class="button" type="submit">Uložit zápis</button>
                    <?php if ($editId): ?>
                        <a class="button secondary" href="minutes.php?id=<?= (int) $editId ?>">Zrušit</a>
                    <?php endif; ?>
                </form>

                <template id="items-template"><?= renderItemRow('__INDEX__', ['level' => 1]) ?></template>
                <template id="events-template"><?= renderEventRow('__INDEX__', []) ?></template>
                <template id="resolutions-template"><?= renderResolutionRow('__INDEX__', []) ?></template>
            </section>
        <?php endif; ?>

        <aside class="card recent-card">
            <h2>Zápisy</h2>
            <p><a class="button" href="minutes.php">Nový zápis</a></p>

            <?php if (calendarConfigured()): ?>
                <h3>Rady z kalendáře</h3>
                <?php if ($calendarError): ?>
                    <p class="message error"><?= h($calendarError) ?></p>
                <?php else: ?>
                    <ul class="meeting-list">
                        <?php foreach (array_reverse($calendarEvents) as $calendarEvent): ?>
                            <?php $linkedId = $linkedKeys[$calendarEvent['key']] ?? null; ?>
                            <li>
                                <a href="minutes.php?<?= $linkedId ? 'id=' . (int) $linkedId : 'event=' . rawurlencode($calendarEvent['key']) ?>">
                                    <strong><?= h(formatDate($calendarEvent['start']->format('Y-m-d'))) ?><?= $calendarEvent['all_day'] ? '' : ' ' . h($calendarEvent['start']->format('G:i')) ?> – <?= h($calendarEvent['title']) ?></strong>
                                    <span><?= $linkedId ? '✔ Zápis existuje' : '＋ Vytvořit zápis' ?></span>
                                </a>
                            </li>
                        <?php endforeach; ?>
                        <?php if (!$calendarEvents): ?><li class="muted">V kalendáři nejsou žádné události.</li><?php endif; ?>
                    </ul>
                    <p class="muted"><a href="minutes.php?refresh=1">Načíst kalendář znovu</a> · <?= isset($_GET['allcal']) ? '<a href="minutes.php">Jen nejbližší</a>' : '<a href="minutes.php?allcal=1">Zobrazit všechny</a>' ?></p>
                <?php endif; ?>
                <h3>Všechny zápisy</h3>
            <?php endif; ?>
            <?php if ($allMeetings): ?>
                <ul class="meeting-list">
                    <?php foreach ($allMeetings as $item): ?>
                        <li>
                            <a href="minutes.php?id=<?= (int) $item['id'] ?>">
                                <strong>č. <?= (int) $item['meeting_number'] ?> – <?= h(formatDate($item['meeting_date'])) ?></strong>
                                <span><?= h($item['place']) ?> · <?= h($item['season']) ?></span>
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php else: ?>
                <p class="muted">Zatím zde nejsou žádné uložené zápisy.</p>
            <?php endif; ?>
        </aside>
    </section>
</main>
<?php renderFooter(); ?>
