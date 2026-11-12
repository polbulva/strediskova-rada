<?php

require __DIR__ . '/src/bootstrap.php';
require __DIR__ . '/src/lib.php';

$currentUser = requireModule('schedule');
$canEdit = canAccess($currentUser, 'schedule_edit');

$errors = [];
$databaseError = '';
$pdo = null;
$groups = [];
$clubrooms = $categories = $meetings = $seasons = [];

try {
    $pdo = databaseConnection();
} catch (Throwable $exception) {
    $databaseError = 'Databázi se nepodařilo připojit.';
}

$season = (string) ($_REQUEST['season'] ?? '');
if (!isValidSeason($season)) {
    $season = seasonFor(date('Y-m-d'));
}

if ($pdo && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    $isMove = $action === 'move';
    $isAjax = !empty($_POST['ajax']);

    if (!$canEdit || !csrfValid()) {
        if ($isMove || $isAjax) {
            http_response_code(403);
            exit;
        }
        $errors[] = $canEdit ? 'Formulář vypršel nebo není platný. Obnovte stránku a zkuste to znovu.' : 'K úpravě rozpisu nemáte oprávnění.';
    } else {
        try {
            if ($isMove) {
                $ok = ctype_digit((string) ($_POST['id'] ?? ''))
                    && moveGroupMeeting($pdo, (int) $_POST['id'], (int) ($_POST['weekday'] ?? 0), $_POST['clubroom_id'] ?? '');
                http_response_code($ok ? 204 : 400);
                exit;
            }

            if ($action === 'save') {
                $error = saveGroupMeeting($pdo, $season, $_POST);
                if ($isAjax) {
                    header('Content-Type: application/json; charset=utf-8');
                    echo json_encode(['error' => $error]);
                    exit;
                }
                if ($error) {
                    $errors[] = $error;
                } else {
                    flash('Schůzka byla uložena.', 'success');
                    redirect('schedule.php?season=' . rawurlencode($season));
                }
            } elseif ($action === 'delete' && ctype_digit((string) ($_POST['id'] ?? ''))) {
                deleteGroupMeeting($pdo, (int) $_POST['id']);
                if ($isAjax) {
                    header('Content-Type: application/json; charset=utf-8');
                    echo json_encode(['error' => null]);
                    exit;
                }
                flash('Schůzka byla smazána.', 'success');
                redirect('schedule.php?season=' . rawurlencode($season));
            } elseif ($action === 'copy') {
                $from = (string) ($_POST['from_season'] ?? '');
                $existing = count(loadGroupMeetings($pdo, $season));
                if (!isValidSeason($from) || $from === $season) {
                    $errors[] = 'Vyberte jinou sezónu, ze které se má rozpis převzít.';
                } elseif ($existing > 0) {
                    $errors[] = 'Sezóna ' . $season . ' už schůzky obsahuje. Převzít lze jen do prázdné sezóny.';
                } else {
                    $count = copySeason($pdo, $from, $season);
                    flash('Převzato ' . $count . ' schůzek ze sezóny ' . $from . '.', 'success');
                    redirect('schedule.php?season=' . rawurlencode($season));
                }
            }
        } catch (Throwable $exception) {
            if ($isMove || $isAjax) {
                http_response_code(500);
                exit;
            }
            $errors[] = 'Změnu se nepodařilo uložit. Zkontrolujte, že jsou spuštěny všechny skripty z database/migrations.';
        }
    }
}

if ($pdo) {
    try {
        $clubrooms = loadLookups($pdo, 'clubroom');
        $categories = loadLookups($pdo, 'category');
        $groups = loadLookups($pdo, 'group');
        $meetings = loadGroupMeetings($pdo, $season);
        $seasons = scheduleSeasons($pdo, seasonFor(date('Y-m-d')));
    } catch (Throwable $exception) {
        $databaseError = 'Rozpis nelze načíst. Zkontrolujte, že jsou spuštěny všechny skripty z database/migrations.';
    }
}

if ($pdo && !$databaseError && isset($_GET['partial'])) {
    echo renderTimetables($clubrooms, $meetings, $canEdit);
    exit;
}

renderHeader('Rozpis schůzek', $currentUser);
?>
<header class="site-header">
    <div class="container">
        <p class="eyebrow">Středisková rada</p>
        <h1>Rozpis schůzek družin</h1>
        <p>Sezóna <?= h($season) ?> (září – červen). Rozpis se zobrazuje i v každém zápisu z rady.</p>
    </div>
</header>

<main class="container">
    <?php renderFlash(); ?>
    <?php if ($databaseError): ?><p class="message error"><?= h($databaseError) ?></p><?php endif; ?>
    <?php foreach ($errors as $error): ?><p class="message error"><?= h($error) ?></p><?php endforeach; ?>

    <?php if ($pdo && !$databaseError): ?>
        <section class="card">
            <form method="get" class="toolbar-inline">
                <label>Sezóna
                    <select name="season" onchange="this.form.submit()">
                        <?php foreach ($seasons as $option): ?>
                            <option value="<?= h($option) ?>"<?= $option === $season ? ' selected' : '' ?>><?= h($option) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <noscript><button class="button" type="submit">Zobrazit</button></noscript>
            </form>

            <?php if ($canEdit && !$meetings): ?>
                <form method="post" class="toolbar-inline copy-form">
                    <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
                    <input type="hidden" name="action" value="copy">
                    <input type="hidden" name="season" value="<?= h($season) ?>">
                    <label>Převzít rozpis ze sezóny
                        <select name="from_season">
                            <?php foreach ($seasons as $option): ?>
                                <?php if ($option !== $season): ?>
                                    <option value="<?= h($option) ?>"<?= $option === previousSeason($season) ? ' selected' : '' ?>><?= h($option) ?></option>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <button class="button secondary" type="submit">Převzít</button>
                </form>
            <?php endif; ?>
            <?php if ($canEdit): ?>
                <p class="muted">Schůzku přidáte tlačítkem + u dne, upravíte kliknutím na kartu a do jiného dne nebo klubovny ji přesunete přetažením.</p>
            <?php endif; ?>
        </section>

        <div class="tt-wrap">
            <?= renderTimetables($clubrooms, $meetings, $canEdit) ?>
        </div>

        <?php if ($canEdit): ?>
            <?= renderScheduleDialog($season, $clubrooms, $categories, $groups ?? []) ?>
        <?php endif; ?>
    <?php endif; ?>
</main>
<?php if ($canEdit): ?>
<script>window.SCHEDULE = {season: <?= json_encode($season) ?>, token: <?= json_encode(csrfToken()) ?>};</script>
<?php endif; ?>
<?php renderFooter(); ?>
