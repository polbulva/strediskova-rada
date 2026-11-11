<?php

require __DIR__ . '/src/bootstrap.php';
require __DIR__ . '/src/lib.php';

$user = requireLogin();

$visible = [];
foreach (appModules() as $key => $module) {
    if (($module['tile'] ?? true) && canAccess($user, $key)) {
        $visible[$key] = $module;
    }
}

$nextEvent = null;
$nextMinutesId = null;
$carried = [];
$calendarProblem = '';
$calendarDebug = '';
if (calendarConfigured()) {
    try {
        $now = new DateTimeImmutable('now', calendarTimezone());
        $found = calendarEvents();
        foreach ($found as $event) {
            $end = $event['end'] ?? $event['start']->modify('+2 hours');
            if ($event['all_day'] ? $event['start']->format('Y-m-d') >= $now->format('Y-m-d') : $end >= $now) {
                $nextEvent = $event;
                break;
            }
        }
        $filter = calendarSettings()['calendar_filter'];
        $calendarDebug = 'Načteno událostí: ' . count($found) . ', filtr: ' . ($filter !== '' ? '„' . $filter . '“' : 'žádný')
            . ', nyní: ' . $now->format('j. n. Y G:i') . ($found ? ', poslední: ' . end($found)['start']->format('j. n. Y') : '') . '.';
    } catch (Throwable $exception) {
        $calendarProblem = 'Kalendář se nepodařilo načíst: ' . $exception->getMessage();
    }

    if ($nextEvent && canAccess($user, 'minutes')) {
        try {
            $pdo = databaseConnection();
            $statement = $pdo->prepare('SELECT id FROM meetings WHERE calendar_event_key = :k');
            $statement->execute(['k' => $nextEvent['key']]);
            $nextMinutesId = $statement->fetchColumn() ?: null;

            $statement = $pdo->query('SELECT id FROM meetings ORDER BY meeting_date DESC, id DESC LIMIT 1');
            $lastId = $statement->fetchColumn();
            if ($lastId) {
                $statement = $pdo->prepare('SELECT title, notes FROM meeting_items WHERE meeting_id = :id AND carry_over = 1 ORDER BY position');
                $statement->execute(['id' => $lastId]);
                $carried = $statement->fetchAll();
            }
        } catch (Throwable $exception) {
            // informace z databáze jsou doplňkové
        }
    }
}

renderHeader('Rozcestník', $user);
?>
<header class="site-header">
    <div class="container">
        <p class="eyebrow">Rozcestník</p>
        <h1>Ahoj, <?= h($user['name']) ?></h1>
        <p><?= h($user['role_name'] ? 'Přihlášen/a jako ' . $user['role_name'] . ($user['unit_name'] ? ' – ' . $user['unit_name'] : '') : 'Přihlášen/a bez role') ?></p>
    </div>
</header>

<main class="container">
    <?php renderFlash(); ?>
    <?php if ($nextEvent): ?>
        <section class="card next-meeting">
            <p class="eyebrow">Nejbližší středisková rada</p>
            <h2><?= h($nextEvent['title']) ?></h2>
            <p class="doc-meta">
                <span><strong>Datum:</strong> <?= h(formatDate($nextEvent['start']->format('Y-m-d'))) ?></span>
                <?php if (!$nextEvent['all_day']): ?>
                    <span><strong>Čas:</strong> <?= h($nextEvent['start']->format('G:i')) ?><?= $nextEvent['end'] ? ' – ' . h($nextEvent['end']->format('G:i')) : '' ?></span>
                <?php endif; ?>
                <?php if ($nextEvent['location'] !== ''): ?><span><strong>Místo:</strong> <?= h($nextEvent['location']) ?></span><?php endif; ?>
            </p>
            <?php if ($nextEvent['description'] !== ''): ?>
                <p><?= nl2br(h($nextEvent['description'])) ?></p>
            <?php endif; ?>
            <?php if ($carried): ?>
                <h3>Body k řešení z minulé rady</h3>
                <ul>
                    <?php foreach ($carried as $row): ?><li><?= h($row['title']) ?></li><?php endforeach; ?>
                </ul>
            <?php endif; ?>
            <?php if (canAccess($user, 'minutes') && ($nextMinutesId || canAccess($user, 'minutes_edit'))): ?>
                <a class="button" href="minutes.php?<?= $nextMinutesId ? 'id=' . (int) $nextMinutesId : 'event=' . rawurlencode($nextEvent['key']) ?>">
                    <?= $nextMinutesId ? 'Otevřít zápis' : 'Vytvořit zápis' ?>
                </a>
            <?php endif; ?>
        </section>
    <?php elseif ($calendarProblem): ?>
        <p class="message error"><?= h($calendarProblem) ?></p>
    <?php elseif (!calendarConfigured()): ?>
        <p class="message error">Kalendář střediskových rad zatím není připojen<?= canAccess($user, 'admin') ? ' – nastavte ho v <a href="admin.php">Administraci</a>' : '' ?>.</p>
    <?php else: ?>
        <p class="message error">V kalendáři nebyla nalezena žádná nadcházející událost.
            <?php if ($calendarDebug): ?><br><small><?= h($calendarDebug) ?></small><?php endif; ?></p>
    <?php endif; ?>
    <?php if ($visible): ?>
        <div class="tiles">
            <?php foreach ($visible as $key => $module): ?>
                <a class="tile tile-<?= h($key) ?>" href="<?= h($module['url']) ?>">
                    <span class="tile-icon" aria-hidden="true"><?= $module['icon'] ?></span>
                    <strong><?= h($module['title']) ?></strong>
                    <span><?= h($module['description']) ?></span>
                </a>
            <?php endforeach; ?>
        </div>
    <?php else: ?>
        <section class="card">
            <h2>Zatím nemáte přístup do žádné části</h2>
            <p>Vaše role (<?= h($user['role_name'] ?: 'bez role') ?>) zatím nemá přiřazená oprávnění. Požádejte správce aplikace, aby vám přístup nastavil.</p>
        </section>
    <?php endif; ?>
</main>
<?php renderFooter(); ?>
