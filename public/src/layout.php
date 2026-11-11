<?php

require_once __DIR__ . '/version.php';

function renderHeader(string $title, ?array $user = null, string $bodyClass = ''): void
{
    ?>
<!doctype html>
<html lang="cs">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= h($title) ?> – Středisková rada</title>
    <meta name="theme-color" content="#eaf0e9">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Roboto:wght@400;500;700&display=swap">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@24,400,0,0&display=block">
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="material.css">
</head>
<body class="<?= h($bodyClass) ?>">
<?php if ($user): ?>
<nav class="topbar">
    <div class="container topbar-inner">
        <a class="brand" href="portal.php"><?php if ($logo = logoUrl()): ?><img class="brand-logo" src="<?= h($logo) ?>" alt=""><?php else: ?><?= icon('camping') ?> <?php endif; ?>Středisková rada</a>
        <div class="topbar-user">
            <span class="who">
                <strong><?= h($user['name']) ?></strong>
                <small><?= h($user['role_name'] ?: 'bez role') ?><?= $user['type'] === 'demo' ? ' · testovací vstup' : '' ?></small>
            </span>
            <a class="button secondary small" href="logout.php">Odhlásit</a>
        </div>
    </div>
</nav>
<?php endif; ?>
<?php
}

function renderFlash(): void
{
    $flash = flash();
    if ($flash) {
        echo '<p class="message ' . ($flash['type'] === 'success' ? 'success' : 'error') . '">' . h($flash['message']) . '</p>';
    }
}

function renderFooter(): void
{
    ?>
<footer class="container footer">Středisková rada · <a href="release-notes.php" title="Novinky v aplikaci">verze <?= h(APP_VERSION) ?></a></footer>
<script src="app.js"></script>
</body>
</html>
<?php
}
