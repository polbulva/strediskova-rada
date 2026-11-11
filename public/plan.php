<?php

require __DIR__ . '/src/bootstrap.php';
require __DIR__ . '/src/lib.php';
require __DIR__ . '/src/plan.php';

$currentUser = requireModule('plan');
$canEdit = canAccess($currentUser, 'plan_edit');

$errors = [];
$databaseError = '';
$pdo = null;
$events = $breaks = $seasons = [];
$formEvent = null;

try {
    $pdo = databaseConnection();
} catch (Throwable $exception) {
    $databaseError = 'Databázi se nepodařilo připojit.';
}

$season = (string) ($_REQUEST['season'] ?? '');
if (!isValidSeason($season)) {
    $season = seasonFor(date('Y-m-d'));
}
$back = 'plan.php?season=' . rawurlencode($season);

if ($pdo && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    if (!$canEdit || !csrfValid()) {
        $errors[] = $canEdit ? 'Formulář vypršel nebo není platný. Obnovte stránku a zkuste to znovu.' : 'K úpravě plánu nemáte oprávnění.';
    } else {
        try {
            if ($action === 'save_event') {
                $rows = parseEventRows($_POST['events'] ?? []);
                $formEvent = $rows[0] ?? null;
                $error = $formEvent ? validateEventRows([$formEvent]) : 'Vyplňte název a termín akce.';
                if (!$error) {
                    savePlanEvent($pdo, $formEvent);
                    flash('Akce byla uložena.', 'success');
                    redirect('plan.php?season=' . rawurlencode(seasonFor($formEvent['event_date'])));
                }
                $errors[] = $error;
            } elseif ($action === 'sync_calendar') {
                $messages = [];
                if (planGoogleConfigured()) {
                    [$rangeFrom, $rangeTo] = seasonRange($season);
                    $removedLocal = dedupePlanEvents($pdo, loadPlanEvents($pdo, $season));
                    $sent = pushPlanToGoogle($pdo, loadPlanEvents($pdo, $season), $rangeFrom, $rangeTo);
                    $messages[] = 'do kalendáře „' . $sent['name'] . '“ odesláno ' . $sent['created'] . ' nových a ' . $sent['updated'] . ' aktualizovaných akcí'
                        . ($sent['removed'] + $removedLocal ? ', odstraněno duplicit: ' . ($sent['removed'] + $removedLocal) : '');
                }
                if (planCalendarUrl() !== '') {
                    $result = syncPlanFromCalendar($pdo, $season);
                    $messages[] = 'z Google přidáno ' . $result['added'] . ' nových akcí';
                }
                if (!$messages) {
                    throw new RuntimeException('Kalendář není v administraci nastaven.');
                }
                flash('Synchronizace hotová: ' . implode(', ', $messages) . '.', 'success');
                redirect($back);
            } elseif ($action === 'delete_events') {
                $ids = array_values(array_filter(array_map('intval', (array) ($_POST['ids'] ?? [])), static fn (int $id): bool => $id > 0));
                if (!$ids) {
                    throw new RuntimeException('Nejdřív zaškrtněte akce ke smazání.');
                }
                $keys = $pdo->prepare('SELECT ical_key FROM events WHERE id = :id');
                $delete = $pdo->prepare('DELETE FROM events WHERE id = :id');
                foreach ($ids as $id) {
                    $keys->execute(['id' => $id]);
                    try {
                        deleteEventFromGoogle($keys->fetchColumn() ?: null);
                    } catch (Throwable $exception) {
                        // akce se z plánu smaže i při výpadku Googlu
                    }
                    $delete->execute(['id' => $id]);
                }
                flash('Smazáno akcí: ' . count($ids) . '.', 'success');
                redirect($back);
            } elseif ($action === 'delete_event' && ctype_digit((string) ($_POST['id'] ?? ''))) {
                $gone = $pdo->prepare('SELECT ical_key FROM events WHERE id = :id');
                $gone->execute(['id' => (int) $_POST['id']]);
                try {
                    deleteEventFromGoogle($gone->fetchColumn() ?: null);
                } catch (Throwable $exception) {
                    // akce se z plánu smaže i při výpadku Googlu
                }
                $pdo->prepare('DELETE FROM events WHERE id = :id')->execute(['id' => (int) $_POST['id']]);
                flash('Akce byla smazána.', 'success');
                redirect($back);
            } elseif ($action === 'save_break') {
                $name = mb_substr(trim((string) ($_POST['break_name'] ?? '')), 0, 150);
                $from = trim((string) ($_POST['break_from'] ?? ''));
                $to = trim((string) ($_POST['break_to'] ?? '')) ?: $from;
                if ($name === '' || !isValidDate($from) || !isValidDate($to) || $to < $from) {
                    $errors[] = 'U prázdnin vyplňte název a platné datum od–do.';
                } else {
                    $pdo->prepare('INSERT INTO school_breaks (name, date_from, date_to) VALUES (:n, :f, :t)')->execute(['n' => $name, 'f' => $from, 't' => $to]);
                    flash('Prázdniny byly přidány.', 'success');
                    redirect($back);
                }
            } elseif ($action === 'delete_break' && ctype_digit((string) ($_POST['id'] ?? ''))) {
                $pdo->prepare('DELETE FROM school_breaks WHERE id = :id')->execute(['id' => (int) $_POST['id']]);
                flash('Prázdniny byly smazány.', 'success');
                redirect($back);
            }
        } catch (RuntimeException $exception) {
            $errors[] = $exception->getMessage();
        } catch (Throwable $exception) {
            $errors[] = 'Změnu se nepodařilo uložit. Zkontrolujte, že jsou spuštěny všechny skripty z database/migrations.';
        }
    }
}

if ($pdo) {
    try {
        $events = loadPlanEvents($pdo, $season);
        $breaks = loadSchoolBreaks($pdo, $season);
        $seasons = planSeasons($pdo, seasonFor(date('Y-m-d')));
        if (!in_array($season, $seasons, true)) {
            $seasons[] = $season;
            rsort($seasons);
        }
        if ($canEdit && !$formEvent && isset($_GET['edit']) && ctype_digit((string) $_GET['edit'])) {
            $statement = $pdo->prepare('SELECT * FROM events WHERE id = :id');
            $statement->execute(['id' => (int) $_GET['edit']]);
            $formEvent = $statement->fetch() ?: null;
        }
    } catch (Throwable $exception) {
        $databaseError = 'Plán nelze načíst. Zkontrolujte, že jsou spuštěny všechny skripty z database/migrations.';
    }
}

$timeline = $databaseError ? [] : buildPlanTimeline($season, $events, $breaks);
$formEvent = $formEvent ?? ['id' => '', 'name' => '', 'event_date' => '', 'event_time' => '', 'end_date' => '', 'end_time' => '', 'patron' => '', 'notes' => ''];
$value = static fn (string $key): string => h((string) ($formEvent[$key] ?? ''));

renderHeader('Plán akcí', $currentUser);
?>
<header class="site-header">
    <div class="container">
        <p class="eyebrow"><a class="back-link" href="portal.php"><?= icon('arrow_back') ?> Rozcestník</a></p>
        <h1>Plán akcí, svátky a prázdniny</h1>
        <p>Rok <?= h($season) ?> (září – srpen). Akce z plánu se nabízejí jako chystané akce v zápisech a akce přidané v zápisu se objeví tady.</p>
    </div>
</header>

<main class="container">
    <?php renderFlash(); ?>
    <?php if ($databaseError): ?><p class="message error"><?= h($databaseError) ?></p><?php endif; ?>
    <?php foreach ($errors as $error): ?><p class="message error"><?= h($error) ?></p><?php endforeach; ?>

    <?php if ($pdo && !$databaseError): ?>
        <section class="card">
            <form method="get" class="toolbar-inline">
                <label>Rok
                    <select name="season" onchange="this.form.submit()">
                        <?php foreach ($seasons as $option): ?>
                            <option value="<?= h($option) ?>"<?= $option === $season ? ' selected' : '' ?>><?= h($option) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <noscript><button class="button" type="submit">Zobrazit</button></noscript>
                <label class="check"><input type="checkbox" id="plan-notes-toggle" checked> Zobrazit poznámky k akcím</label>
                <a class="button secondary" id="plan-pdf-full" href="plan_pdf.php?season=<?= h(rawurlencode($season)) ?>">Stáhnout PDF</a>
                <a class="button secondary" href="plan_pdf.php?season=<?= h(rawurlencode($season)) ?>&amp;simple=1">PDF: jen termíny a názvy</a>
            </form>
            <?php if ($canEdit && (planCalendarUrl() !== '' || planGoogleConfigured())): ?>
                <form method="post" class="toolbar-inline">
                    <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
                    <input type="hidden" name="action" value="sync_calendar">
                    <input type="hidden" name="season" value="<?= h($season) ?>">
                    <button class="button secondary" type="submit"><?= icon('sync') ?> Synchronizovat s kalendářem</button>
                    <span class="muted">Odešle akce roku <?= h($season) ?> do Google Kalendáře<?= planCalendarUrl() !== "" ? " a načte z něj nové akce" : "" ?>.</span>
                </form>
            <?php endif; ?>
            <?php if ($canEdit && !planCalendarUrl() && !planGoogleConfigured()): ?>
                <p class="muted">Synchronizaci s Google Kalendářem nastavíte v Administraci → Připojení.</p>
            <?php endif; ?>
            <form method="get" action="plan_pdf.php" class="toolbar-inline">
                <label>Export za kalendářní rok
                    <input type="number" name="year" min="2000" max="2099" value="<?= (int) date('Y') ?>" style="width: 6rem">
                </label>
                <label class="check"><input type="checkbox" name="simple" value="1"> jen termíny a názvy</label>
                <label class="check"><input type="checkbox" name="notes" value="0"> bez poznámek</label>
                <button class="button secondary" type="submit">Stáhnout PDF</button>
            </form>
        </section>

        <?php if ($canEdit): ?>
            <section class="card" id="event-form">
                <h2><?= $formEvent['id'] ? 'Upravit akci' : 'Přidat akci' ?></h2>
                <form method="post">
                    <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
                    <input type="hidden" name="action" value="save_event">
                    <input type="hidden" name="season" value="<?= h($season) ?>">
                    <input type="hidden" name="events[0][id]" value="<?= $value('id') ?>">
                    <div class="form-grid">
                        <label>Název akce <span class="required">*</span>
                            <input type="text" name="events[0][name]" required maxlength="255" value="<?= $value('name') ?>">
                        </label>
                        <label>Termín <span class="required">*</span>
                            <input type="date" name="events[0][date]" required value="<?= $value('event_date') ?>">
                        </label>
                        <label>Čas (nepovinně)
                            <input type="time" name="events[0][time]" value="<?= h(substr((string) ($formEvent['event_time'] ?? ''), 0, 5)) ?>">
                        </label>
                        <label>Konec (nepovinně)
                            <input type="date" name="events[0][end_date]" value="<?= $value('end_date') ?>">
                        </label>
                        <label>Čas konce (nepovinně)
                            <input type="time" name="events[0][end_time]" value="<?= h(substr((string) ($formEvent['end_time'] ?? ''), 0, 5)) ?>">
                        </label>
                        <label>Garant
                            <input type="text" name="events[0][patron]" maxlength="255" value="<?= $value('patron') ?>">
                        </label>
                    </div>
                    <div class="event-summary"><span>Shrnutí</span><?= renderRichEditor((string) ($formEvent['notes'] ?? ''), 'notes', 'events[0][notes]') ?></div>
                    <button class="button" type="submit"><?= $formEvent['id'] ? 'Uložit změny' : 'Přidat do plánu' ?></button>
                    <?php if ($formEvent['id']): ?><a class="button secondary" href="<?= h($back) ?>">Zrušit</a><?php endif; ?>
                </form>
            </section>
        <?php endif; ?>

        <?php if (!$timeline): ?><p class="muted">V tomto roce zatím nejsou žádné akce.</p><?php endif; ?>
        <?php if ($canEdit && $events): ?>
            <form method="post" id="bulk-delete" class="card toolbar-inline" onsubmit="return confirm('Opravdu smazat všechny vybrané akce?');">
                <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
                <input type="hidden" name="action" value="delete_events">
                <input type="hidden" name="season" value="<?= h($season) ?>">
                <label class="check"><input type="checkbox" id="plan-select-all"> Vybrat všechny akce</label>
                <button class="button danger small" type="submit">Smazat vybrané</button>
                <span class="muted">Zaškrtněte akce v seznamu níže.</span>
            </form>
        <?php endif; ?>
        <?php foreach ($timeline as $month => $entries): ?>
            <section class="card plan-month">
                <h2><?= h(MONTH_NAMES[(int) substr($month, 5, 2)]) ?> <?= h(substr($month, 0, 4)) ?></h2>
                <ul class="plan-list">
                    <?php foreach ($entries as $entry): ?>
                        <?php if ($entry['kind'] === 'event'): $event = $entry['event']; ?>
                            <li class="plan-event">
                                <span class="plan-date"><?= h(eventWhen($event)) ?></span>
                                <div>
                                    <strong><?= h($event['name']) ?></strong><?= $event['patron'] !== '' ? ' · garant: ' . h($event['patron']) : '' ?>
                                    <div class="plan-notes"><?= renderNotes((string) $event['notes']) ?></div>
                                </div>
                                <?php if ($canEdit): ?>
                                    <span class="plan-actions">
                                        <input type="checkbox" name="ids[]" value="<?= (int) $event['id'] ?>" form="bulk-delete" class="plan-select" title="Vybrat ke smazání" style="width: auto">
                                        <a class="button secondary small" href="plan.php?season=<?= h(rawurlencode($season)) ?>&amp;edit=<?= (int) $event['id'] ?>#event-form">Upravit</a>
                                        <form method="post" onsubmit="return confirm('Opravdu smazat tuto akci?');">
                                            <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
                                            <input type="hidden" name="action" value="delete_event">
                                            <input type="hidden" name="season" value="<?= h($season) ?>">
                                            <input type="hidden" name="id" value="<?= (int) $event['id'] ?>">
                                            <button class="button danger small" type="submit">Smazat</button>
                                        </form>
                                    </span>
                                <?php endif; ?>
                            </li>
                        <?php elseif ($entry['kind'] === 'holiday'): ?>
                            <li class="plan-holiday"><span class="plan-date"><?= h(formatDate($entry['date'])) ?></span><div><?= icon('flag') ?> <?= h($entry['label']) ?> <span class="muted">(státní svátek)</span></div></li>
                        <?php else: ?>
                            <li class="plan-break">
                                <span class="plan-date"><?= h(formatDate($entry['from_real'])) ?><?= $entry['to'] !== $entry['from_real'] ? ' – ' . h(formatDate($entry['to'])) : '' ?></span>
                                <div><?= icon('school') ?> <?= h($entry['label']) ?> <span class="muted">(školní prázdniny)</span></div>
                                <?php if ($canEdit): ?>
                                    <span class="plan-actions">
                                        <form method="post" onsubmit="return confirm('Smazat tyto prázdniny?');">
                                            <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
                                            <input type="hidden" name="action" value="delete_break">
                                            <input type="hidden" name="season" value="<?= h($season) ?>">
                                            <input type="hidden" name="id" value="<?= (int) $entry['id'] ?>">
                                            <button class="button danger small" type="submit">Smazat</button>
                                        </form>
                                    </span>
                                <?php endif; ?>
                            </li>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </ul>
            </section>
        <?php endforeach; ?>

        <?php if ($canEdit): ?>
            <section class="card">
                <h2>Přidat školní prázdniny</h2>
                <p class="muted">Státní svátky se doplňují automaticky. Termíny školních prázdnin (např. jarních, které se liší podle okresu) zadejte ručně.</p>
                <form method="post">
                    <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
                    <input type="hidden" name="action" value="save_break">
                    <input type="hidden" name="season" value="<?= h($season) ?>">
                    <div class="form-grid">
                        <label>Název <input type="text" name="break_name" required maxlength="150" placeholder="např. Jarní prázdniny"></label>
                        <label>Od <input type="date" name="break_from" required></label>
                        <label>Do <input type="date" name="break_to"></label>
                    </div>
                    <button class="button" type="submit">Přidat prázdniny</button>
                </form>
            </section>
        <?php endif; ?>
    <?php endif; ?>
</main>
<script>
(function () {
    var toggle = document.getElementById('plan-notes-toggle');
    var pdf = document.getElementById('plan-pdf-full');
    if (!toggle) { return; }
    var base = pdf.getAttribute('href');
    function apply() {
        document.querySelectorAll('.plan-notes').forEach(function (el) { el.hidden = !toggle.checked; });
        pdf.setAttribute('href', base + (toggle.checked ? '' : '&notes=0'));
        try { localStorage.setItem('planNotes', toggle.checked ? '1' : '0'); } catch (e) {}
    }
    try { toggle.checked = localStorage.getItem('planNotes') !== '0'; } catch (e) {}
    var all = document.getElementById('plan-select-all');
    if (all) {
        all.addEventListener('change', function () {
            document.querySelectorAll('.plan-select').forEach(function (box) { box.checked = all.checked; });
        });
    }
    toggle.addEventListener('change', apply);
    apply();
})();
</script>
<?php renderFooter(); ?>
