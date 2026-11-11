<?php

require __DIR__ . '/src/bootstrap.php';
require __DIR__ . '/src/lib.php';

$user = requireLogin();

renderHeader('Novinky', $user);
?>
<header class="site-header">
    <div class="container">
        <p class="eyebrow"><a class="back-link" href="portal.php">← Rozcestník</a></p>
        <h1>Novinky v aplikaci</h1>
        <p>Aktuální verze <?= h(APP_VERSION) ?></p>
    </div>
</header>

<main class="container">
    <?php foreach (releaseNotes() as $release): ?>
        <section class="card">
            <h2>Verze <?= h($release['version']) ?> <small class="muted">(<?= h(formatDate($release['date'])) ?>)</small></h2>
            <ul>
                <?php foreach ($release['changes'] as $change): ?><li><?= h($change) ?></li><?php endforeach; ?>
            </ul>
        </section>
    <?php endforeach; ?>
</main>
<?php renderFooter(); ?>
