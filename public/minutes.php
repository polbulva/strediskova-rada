<?php

require __DIR__ . '/src/bootstrap.php';
require __DIR__ . '/src/lib.php';

$currentUser = requireModule('minutes');
$canEditMinutes = canAccess($currentUser, 'minutes_edit');

$fields = [
    'meeting_number', 'meeting_date', 'time_from', 'time_to', 'place', 'attendees', 'online_attendees',
    'absentees', 'next_meeting_date', 'next_meeting_time', 'author', 'verifier', 'author_signature', 'verifier_signature', 'calendar_event_key',
];
$values = array_fill_keys($fields, '');
$values['meeting_date'] = date('Y-m-d');
$items = [];
$resolutions = [];
$upcomingEvents = [];
$pastEvents = [];
$loadedEventIds = [];
$trips = [];
$tripUnits = [];
$tripGroups = [];
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

if ($pdo && $_SERVER['REQUEST_METHOD'] === 'POST' && !$canEditMinutes) {
    http_response_code(403);
    exit('K úpravě zápisů nemáte oprávnění.');
}

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
        $trips = parseTripRows($_POST['trips'] ?? []);
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

        if ($eventError = validateEventRows($upcomingEvents) ?? validatePastRows($postedPast)) {
            $errors[] = $eventError;
        }

        if (!$errors) {
            try {
                $id = saveMeeting($pdo, $editId, $values, $items, $resolutions, $upcomingEvents, $postedPast, $loadedEventIds, $trips);
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
$wantForm = $canEditMinutes && ($isPost || isset($_GET['new']) || isset($_GET['edit']) || isset($_GET['event']) || isset($_GET['from']));

if ($isPost && isset($postedPast)) {
    $pastEvents = $postedPast;
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
        $trips = $existing['trips'];
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
        } elseif (!$isPost && $wantForm) {
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

$showForm = !$meeting && $pdo && !$databaseError && $wantForm;
if ($showForm) {
    try {
        $tripUnits = loadLookups($pdo, 'unit');
        $tripGroups = loadLookups($pdo, 'group');
    } catch (Throwable $exception) {
        $tripUnits = $tripGroups = [];
    }
}
renderHeader('Zápisy z rady', $currentUser);
?>
<header class="site-header">
    <div class="container">
        <p class="eyebrow">Středisková rada</p>
        <h1>Zápisy z rady</h1>
        <p><?= $canEditMinutes ? 'Vytvořte, uložte a vytiskněte zápis z rady.' : 'Zápisy z rad ke čtení a stažení do PDF.' ?></p>
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
                <a class="button secondary" href="minutes.php"><?= icon('arrow_back') ?> Zpět</a>
                <?php if ($canEditMinutes): ?><a class="button secondary" href="minutes.php?edit=<?= (int) $meeting['id'] ?>">Upravit</a><?php endif; ?>
                <button class="button secondary" type="button" onclick="window.print()">Tisknout</button>
                <a class="button secondary" href="minutes_pdf.php?id=<?= (int) $meeting['id'] ?>">Stáhnout PDF</a>
                <?php if ($canEditMinutes): ?>
                <form method="post" onsubmit="return confirm('Opravdu smazat tento zápis?');">
                    <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id" value="<?= (int) $meeting['id'] ?>">
                    <button class="button danger" type="submit">Smazat</button>
                </form>
                <?php endif; ?>
            </div>

            <?php require __DIR__ . '/src/minutes_document.php'; ?>
        </article>
    <?php endif; ?>

    <section class="layout single">
        <?php if ($showForm): ?>
            <section class="card form-card">
                <h2><?= $editId ? 'Úprava zápisu' : 'Nový zápis' ?></h2>

                <?php if (!$editId && !$isPost): ?>
                    <form method="get" class="carry-form">
                        <input type="hidden" name="new" value="1">
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
                        <p class="muted"><?= icon('event') ?> Zápis je navázán na událost v kalendáři.</p>
                    <?php endif; ?>
                    <p class="muted">Rozpis schůzek družin se do zápisu doplní automaticky podle sezóny. <?php if (canAccess($currentUser, 'schedule_edit')): ?><a href="schedule.php">Upravit rozpis</a><?php endif; ?></p>
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
                    <?php
                    $tripHost = null;
                    foreach (array_values($items) as $index => $item) {
                        if (($item['item_type'] ?? '') === 'trips') {
                            $tripHost = $index;
                            break;
                        }
                    }
                    $timetableHost = null;
                    foreach (array_values($items) as $index => $item) {
                        if (($item['item_type'] ?? '') === 'timetable') {
                            $timetableHost = $index;
                            break;
                        }
                    }
                    $ttSeason = seasonFor($values['meeting_date'] ?: date('Y-m-d'));
                    $canEditSchedule = $pdo && canAccess($currentUser, 'schedule_edit');
                    $timetableHtml = renderSeasonTimetables($ttSeason, false, (bool) $canEditSchedule);
                    $pastHost = $upcomingHost = null;
                    foreach (array_values($items) as $index => $item) {
                        if (($item['item_type'] ?? '') === 'past' && $pastHost === null) { $pastHost = $index; }
                        if (($item['item_type'] ?? '') === 'upcoming' && $upcomingHost === null) { $upcomingHost = $index; }
                    }
                    $pastHtml = renderPastEventsEditor($pastEvents);
                    $upcomingHtml = renderUpcomingEventsEditor($upcomingEvents, $loadedEventIds, loadPickableEvents($pdo, $values['meeting_date'] ?: date('Y-m-d'), array_column($upcomingEvents, 'id')));
                    $tripContext = ['trips' => $trips, 'units' => $tripUnits, 'groups' => $tripGroups];
                    ?>
                    <div id="items" data-name="items">
                        <?php foreach (array_values($items) as $index => $item): ?>
                            <?= renderItemRow((string) $index, $item, $index === $tripHost ? $tripContext : null, $index === $timetableHost ? $timetableHtml : null, $index === $pastHost ? $pastHtml : ($index === $upcomingHost ? $upcomingHtml : '')) ?>
                        <?php endforeach; ?>
                    </div>
                    <button class="button secondary" type="button" data-add="items">+ Přidat bod</button>

                    <?php if ($timetableHost === null): ?>
                        <details class="form-timetable" open>
                            <summary>Rozpis schůzek družin<?= $canEditSchedule ? '' : ' (pro nahlédnutí)' ?></summary>
                            <?= $timetableHtml ?>
                        </details>
                    <?php endif; ?>
                    <?php if ($tripHost === null): ?>
                        <h3>Výpravy</h3>
                        <?= renderTripsEditor($trips, $tripUnits, $tripGroups) ?>
                    <?php endif; ?>
                    <?php if ($pastHost === null): ?>
                        <h3>Proběhlé akce – vyhodnocení</h3>
                        <?= $pastHtml ?>
                    <?php endif; ?>
                    <?php if ($upcomingHost === null): ?>
                        <h3>Nejbližší chystané akce</h3>
                        <?= $upcomingHtml ?>
                    <?php endif; ?>
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
                    <div class="sign-row">
                        <?php foreach (['author' => 'Podpis zapisovatele', 'verifier' => 'Podpis ověřovatele'] as $who => $caption): ?>
                            <div class="sign-pad" data-sign>
                                <span><?= h($caption) ?></span>
                                <canvas width="400" height="150"></canvas>
                                <input type="hidden" name="<?= $who ?>_signature" value="<?= h($values[$who . '_signature']) ?>">
                                <button class="button secondary" type="button" data-sign-clear>Smazat podpis</button>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    </div>

                    <div class="form-actions">
                        <button class="button" type="submit">Uložit zápis</button>
                        <?php if ($editId): ?>
                            <a class="button secondary" href="minutes.php?id=<?= (int) $editId ?>">Zrušit</a>
                        <?php endif; ?>
                    </div>
                </form>

                <?php if ($canEditSchedule): ?>
                    <?php
                    try {
                        echo renderScheduleDialog($ttSeason, loadLookups($pdo, 'clubroom'), loadLookups($pdo, 'category'), $tripGroups);
                    } catch (Throwable $exception) {
                    }
                    ?>
                    <script>window.SCHEDULE = {season: <?= json_encode($ttSeason) ?>, token: <?= json_encode(csrfToken()) ?>, ajax: true};</script>
                <?php endif; ?>
                <template id="items-template"><?= renderItemRow('__INDEX__', ['level' => 1]) ?></template>
                <template id="trips-template"><?= renderTripRow('__INDEX__', [], $tripUnits, $tripGroups) ?></template>
                <template id="past-template"><?= renderPastEventRow('__INDEX__', []) ?></template>
                <template id="events-template"><?= renderEventRow('__INDEX__', []) ?></template>
                <template id="resolutions-template"><?= renderResolutionRow('__INDEX__', []) ?></template>
            </section>
        <?php endif; ?>

        <?php if (!$showForm): ?>
        <aside class="card recent-card">
            <h2>Zápisy</h2>
            <?php if ($canEditMinutes): ?><p><a class="button" href="minutes.php?new=1">＋ Nový zápis bez události</a></p><?php endif; ?>

            <?php if (calendarConfigured()): ?>
                <h3>Rady z kalendáře</h3>
                <?php if ($calendarError): ?>
                    <p class="message error"><?= h($calendarError) ?></p>
                <?php else: ?>
                    <ul class="meeting-list">
                        <?php foreach (array_reverse($calendarEvents) as $calendarEvent): ?>
                            <?php $linkedId = $linkedKeys[$calendarEvent['key']] ?? null; ?>
                            <?php if (!$linkedId && !$canEditMinutes) { continue; } ?>
                            <li>
                                <a href="minutes.php?<?= $linkedId ? 'id=' . (int) $linkedId : 'event=' . rawurlencode($calendarEvent['key']) ?>">
                                    <strong><?= h(formatDate($calendarEvent['start']->format('Y-m-d'))) ?><?= $calendarEvent['all_day'] ? '' : ' ' . h($calendarEvent['start']->format('G:i')) ?> – <?= h($calendarEvent['title']) ?></strong>
                                    <span><?= $linkedId ? icon('check_circle') . ' Zápis existuje' : icon('add') . ' Vytvořit zápis' ?></span>
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
        <?php endif; ?>
    </section>
</main>
<?php renderFooter(); ?>
