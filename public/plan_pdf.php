<?php

require __DIR__ . '/src/bootstrap.php';
require __DIR__ . '/src/lib.php';
require __DIR__ . '/src/plan.php';
require __DIR__ . '/src/pdf.php';

$currentUser = requireModule('plan');

$season = (string) ($_GET['season'] ?? '');
if (!isValidSeason($season)) {
    $season = seasonFor(date('Y-m-d'));
}

try {
    $pdo = databaseConnection();
    $timeline = buildPlanTimeline($season, loadPlanEvents($pdo, $season), loadSchoolBreaks($pdo, $season));
} catch (Throwable $exception) {
    http_response_code(500);
    exit('Plán se nepodařilo načíst.');
}

ob_start();
$logo = logoPath();
?>
<?php if ($logo): ?><img class="doc-logo" src="<?= h($logo) ?>" alt=""><?php endif; ?>
<h2 class="doc-title">Plán akcí <?= h($season) ?></h2>
<p class="muted">Akce, státní svátky a školní prázdniny (září – srpen)</p>
<?php if (!$timeline): ?><p class="muted">V tomto roce zatím nejsou žádné akce.</p><?php endif; ?>
<?php foreach ($timeline as $month => $entries): ?>
    <h3><?= h(MONTH_NAMES[(int) substr($month, 5, 2)]) ?> <?= h(substr($month, 0, 4)) ?></h3>
    <table class="doc-table">
        <thead><tr><th style="width: 24%">Termín</th><th>Akce</th><th style="width: 22%">Garant</th></tr></thead>
        <tbody>
        <?php foreach ($entries as $entry): ?>
            <?php if ($entry['kind'] === 'event'): $event = $entry['event']; ?>
                <tr>
                    <td><?= h(formatDate($event['event_date'])) ?><?= eventTime($event) !== '' ? ' ' . h(eventTime($event)) : '' ?></td>
                    <td><strong><?= h($event['name']) ?></strong><?= renderNotes((string) $event['notes']) ?></td>
                    <td><?= h($event['patron']) ?></td>
                </tr>
            <?php elseif ($entry['kind'] === 'holiday'): ?>
                <tr class="plan-holiday-row">
                    <td><?= h(formatDate($entry['date'])) ?></td>
                    <td colspan="2" class="muted"><?= h($entry['label']) ?> (státní svátek)</td>
                </tr>
            <?php else: ?>
                <tr class="plan-break-row">
                    <td><?= h(formatDate($entry['from_real'])) ?><?= $entry['to'] !== $entry['from_real'] ? ' – ' . h(formatDate($entry['to'])) : '' ?></td>
                    <td colspan="2" class="muted"><?= h($entry['label']) ?> (školní prázdniny)</td>
                </tr>
            <?php endif; ?>
        <?php endforeach; ?>
        </tbody>
    </table>
<?php endforeach; ?>
<?php
streamPdf(ob_get_clean(), 'plan_akci_' . str_replace('/', '_', $season) . '.pdf', isset($_GET['inline']));
