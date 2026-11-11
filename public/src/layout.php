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
        <button class="nav-toggle" type="button" aria-label="Menu" aria-expanded="false" aria-controls="topbar-nav" data-nav-toggle title="Menu"><?= icon('menu') ?></button>
        <div class="topbar-nav" id="topbar-nav">
            <?php
            $currentPage = basename((string) ($_SERVER['SCRIPT_NAME'] ?? ''));
            $navLinks = [['portal.php', 'Rozcestník', icon('home')]];
            foreach (appModules() as $moduleKey => $module) {
                if ($moduleKey !== 'admin' && ($module['tile'] ?? true) && canAccess($user, $moduleKey)) {
                    $navLinks[] = [$module['url'], $module['title'], $module['icon']];
                }
            }
            foreach ($navLinks as [$navUrl, $navTitle, $navIcon]): ?>
                <a class="nav-link<?= $currentPage === $navUrl ? ' active' : '' ?>" href="<?= h($navUrl) ?>"<?= $currentPage === $navUrl ? ' aria-current="page"' : '' ?>><?= $navIcon ?> <?= h($navTitle) ?></a>
            <?php endforeach; ?>
        </div>
        <div class="topbar-user">
            <span class="who">
                <strong><?= h($user['name']) ?></strong>
                <?php $userRoles = (array) ($user['roles'] ?? []); ?>
                <?php if (count($userRoles) > 1): ?>
                    <form class="role-switch" method="post" action="switch_role.php">
                        <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
                        <input type="hidden" name="return" value="<?= h(basename((string) ($_SERVER['SCRIPT_NAME'] ?? 'portal.php'))) ?>">
                        <select name="role" aria-label="Role" title="Zobrazit aplikaci pod rol?" onchange="this.form.submit()">
                            <?php foreach ($userRoles as $roleIndex => $roleOption): ?>
                                <option value="<?= (int) $roleIndex ?>"<?= ($roleOption['role_key'] === $user['role_key'] && ($roleOption['unit_id'] ?? null) === ($user['unit_id'] ?? null)) ? ' selected' : '' ?>><?= h(($roleOption['unit_name'] !== '' ? $roleOption['unit_name'] . ': ' : '') . ($roleOption['role_name'] ?: 'bez role')) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </form>
                <?php else: ?>
                    <small><?= h($user['role_name'] ?: 'bez role') ?><?= $user['type'] === 'demo' ? ' · testovací vstup' : '' ?></small>
                <?php endif; ?>
            </span>
            <?php if (canAccess($user, 'admin')): ?>
                <a class="icon-button<?= basename((string) ($_SERVER['SCRIPT_NAME'] ?? '')) === 'admin.php' ? ' active' : '' ?>" href="admin.php" title="Administrace" aria-label="Administrace"><?= icon('settings') ?></a>
            <?php endif; ?>
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
