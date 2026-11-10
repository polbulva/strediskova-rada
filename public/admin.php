<?php

require __DIR__ . '/src/bootstrap.php';
require __DIR__ . '/src/calendar.php';
require __DIR__ . '/src/lib.php';

$currentUser = requireModule('admin');
$modules = appModules();
$errors = [];
$pdo = null;

try {
    $pdo = databaseConnection();
} catch (Throwable $exception) {
    $errors[] = 'Databázi se nepodařilo připojit.';
}

function splitRoleKeys(string $text): array
{
    $keys = [];
    foreach (preg_split('/[\r\n,;]+/', $text) as $key) {
        $key = trim($key);
        if ($key !== '' && mb_strlen($key) <= 100) {
            $keys[$key] = $key;
        }
    }
    return array_values($keys);
}

if ($pdo && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (empty($_POST) && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
        $errors[] = 'Odeslaný soubor je větší než limit hostingu (post_max_size = ' . ini_get('post_max_size') . '). Zmenšete logo.';
    } elseif (!csrfValid()) {
        $errors[] = 'Formulář vypršel nebo není platný. Obnovte stránku a zkuste to znovu.';
    } elseif (($_POST['action'] ?? '') === 'skautis') {
        $appId = trim((string) ($_POST['skautis_app_id'] ?? ''));
        if ($appId !== '' && !preg_match('/^[0-9a-fA-F-]{36}$/', $appId)) {
            $errors[] = 'ID aplikace skautIS musí mít tvar GUID (např. 12345678-1234-1234-1234-123456789abc).';
        } else {
            try {
                saveAppSettings($pdo, [
                    'skautis_app_id' => $appId,
                    'skautis_test' => ($_POST['skautis_test'] ?? '1') === '1',
                    'demo_login' => !empty($_POST['demo_login']),
                ]);
                flash('Nastavení skautISu bylo uloženo.', 'success');
                redirect('admin.php?tab=connections');
            } catch (Throwable $exception) {
                $errors[] = 'Nastavení se nepodařilo uložit. Zkontrolujte, že je databáze aktualizována (tabulka app_settings).';
            }
        }
    } elseif (($_POST['action'] ?? '') === 'logo') {
        if (!empty($_POST['remove_logo'])) {
            removeLogo();
            flash('Logo bylo odstraněno.', 'success');
            redirect('admin.php?tab=connections');
        } elseif ($logoError = saveUploadedLogo($_FILES['logo'] ?? [])) {
            $errors[] = $logoError;
        } else {
            flash('Logo bylo nahráno.', 'success');
            redirect('admin.php?tab=connections');
        }
    } elseif (($_POST['action'] ?? '') === 'calendar') {
        $url = trim((string) ($_POST['calendar_ics_url'] ?? ''));
        $filter = trim((string) ($_POST['calendar_filter'] ?? ''));
        if ($url !== '' && !preg_match('~^https://\S+$~i', $url)) {
            $errors[] = 'Adresa kalendáře musí začínat https://.';
        } else {
            try {
                if ($url !== '') {
                    $body = calendarHttpGet($url);
                    if (stripos($body, 'BEGIN:VCALENDAR') === false) {
                        throw new RuntimeException('Adresa nevrací kalendář ve formátu iCal.');
                    }
                }
                saveCalendarSettings($pdo, $url, $filter);
                flash($url === '' ? 'Kalendář byl odpojen.' : 'Kalendář byl připojen a uložen.', 'success');
                redirect('admin.php?tab=connections');
            } catch (RuntimeException $exception) {
                $errors[] = 'Kalendář nebyl uložen: ' . $exception->getMessage();
            } catch (Throwable $exception) {
                $errors[] = 'Nastavení se nepodařilo uložit. Zkontrolujte, že je databáze aktualizována (tabulka app_settings).';
            }
        }
    } elseif (($_POST['action'] ?? '') === 'lookups') {
        try {
            foreach (lookupTypes() as $type => $label) {
                saveLookups($pdo, $type, (array) ($_POST['lookups'][$type] ?? []), (array) ($_POST['new'][$type] ?? []), (array) ($_POST['newparent'][$type] ?? []));
            }
            saveAgendaTemplate($pdo, (array) ($_POST['agenda'] ?? []), (array) ($_POST['agenda_new'] ?? []));
            flash('Číselníky byly uloženy.', 'success');
            redirect('admin.php?tab=lookups');
        } catch (Throwable $exception) {
            $errors[] = 'Číselníky se nepodařilo uložit. Zkontrolujte, že jsou spuštěny všechny skripty z database/migrations.';
        }
    } elseif (($_POST['action'] ?? '') === 'permissions') {
        $newSettings = [];
        foreach ($modules as $key => $module) {
            $policy = $key === 'admin' ? 'roles' : (($_POST['policy'][$key] ?? '') === 'roles' ? 'roles' : 'all');
            $selected = array_map('strval', (array) ($_POST['roles'][$key] ?? []));
            $roles = array_values(array_unique(array_merge(
                array_filter(array_map('trim', $selected), static fn (string $r): bool => $r !== '' && mb_strlen($r) <= 100),
                splitRoleKeys((string) ($_POST['extra_roles'][$key] ?? ''))
            )));
            $newSettings[$key] = ['policy' => $policy, 'roles' => $roles];
        }

        $adminRoles = $newSettings['admin']['roles'];
        if (!isSuperAdmin($currentUser) && !in_array($currentUser['role_key'], $adminRoles, true)) {
            $errors[] = 'Nastavení by vám odebralo přístup do administrace, proto nebylo uloženo.';
        }

        if (!$errors) {
            try {
                $pdo->beginTransaction();
                foreach ($newSettings as $key => $setting) {
                    $pdo->prepare(
                        'INSERT INTO module_settings (module, policy) VALUES (:module, :policy)
                         ON DUPLICATE KEY UPDATE policy = VALUES(policy)'
                    )->execute(['module' => $key, 'policy' => $setting['policy']]);
                    $pdo->prepare('DELETE FROM module_access WHERE module = :module')->execute(['module' => $key]);
                    $insert = $pdo->prepare('INSERT INTO module_access (module, role_key) VALUES (:module, :role)');
                    foreach ($setting['roles'] as $role) {
                        $insert->execute(['module' => $key, 'role' => $role]);
                    }
                }
                $pdo->commit();
                flash('Oprávnění byla uložena.', 'success');
                redirect('admin.php?tab=permissions');
            } catch (Throwable $exception) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $errors[] = 'Oprávnění se nepodařilo uložit. Zkontrolujte, že je databáze připravena podle database/migrations.';
            }
        }
    }
}

$known = [];
$settings = [];
if ($pdo) {
    try {
        foreach (knownRoles($pdo) as $role) {
            $known[$role['role_key']] = $role;
        }
        foreach ($modules as $key => $module) {
            $settings[$key] = modulePolicy($pdo, $key);
            foreach ($settings[$key]['roles'] as $roleKey) {
                $known[$roleKey] ??= ['role_key' => $roleKey, 'label' => '', 'last_seen_at' => ''];
            }
        }
    } catch (Throwable $exception) {
        $errors[] = 'Nastavení nelze načíst. Zkontrolujte, že je databáze připravena podle database/migrations.';
    }
}

$tabByAction = ['skautis' => 'connections', 'calendar' => 'connections', 'logo' => 'connections', 'lookups' => 'lookups'];
$tab = $_SERVER['REQUEST_METHOD'] === 'POST'
    ? ($tabByAction[$_POST['action'] ?? ''] ?? 'permissions')
    : (in_array($_GET['tab'] ?? '', ['permissions', 'lookups', 'connections'], true) ? $_GET['tab'] : 'permissions');
$lookupData = [];
$agendaRows = [];
if ($pdo) {
    try {
        $agendaRows = $pdo->query('SELECT id, level, title, always_carry FROM agenda_template ORDER BY position, id')->fetchAll();
        foreach (lookupTypes() as $type => $typeLabel) {
            $lookupData[$type] = loadLookups($pdo, $type);
        }
    } catch (Throwable $exception) {
        $errors[] = 'Číselníky nelze načíst. Zkontrolujte, že jsou spuštěny všechny skripty z database/migrations.';
    }
}
renderHeader('Administrace', $currentUser);
?>
<header class="site-header">
    <div class="container">
        <p class="eyebrow"><a class="back-link" href="portal.php">← Rozcestník</a></p>
        <h1>Administrace</h1>
        <p>Určete, které role ze skautISu mají přístup do jednotlivých částí aplikace.</p>
    </div>
</header>

<main class="container">
    <?php renderFlash(); ?>
    <?php foreach ($errors as $error): ?>
        <p class="message error"><?= h($error) ?></p>
    <?php endforeach; ?>

    <nav class="tabs">
        <a href="admin.php?tab=permissions"<?= $tab === 'permissions' ? ' class="active"' : '' ?>>Oprávnění</a>
        <a href="admin.php?tab=lookups"<?= $tab === 'lookups' ? ' class="active"' : '' ?>>Číselníky</a>
        <a href="admin.php?tab=connections"<?= $tab === 'connections' ? ' class="active"' : '' ?>>Připojení</a>
    </nav>
    <?php if ($pdo && $tab === 'connections'): ?>
        <section class="card">
            <h2>🖼 Logo střediska</h2>
            <p class="muted">Zobrazuje se v hlavičce aplikace a v zápisu (včetně tisku). Formáty PNG, JPEG, GIF, WebP do 2 MB.</p>
            <?php if ($currentLogo = logoUrl()): ?><p><img class="logo-preview" src="<?= h($currentLogo) ?>" alt="Logo"></p><?php endif; ?>
            <form method="post" enctype="multipart/form-data">
                <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
                <input type="hidden" name="action" value="logo">
                <label>Nahrát nové logo <input type="file" name="logo" accept="image/png,image/jpeg,image/gif,image/webp"></label>
                <button class="button" type="submit">Nahrát logo</button>
                <?php if ($currentLogo): ?><button class="button danger" type="submit" name="remove_logo" value="1">Odstranit logo</button><?php endif; ?>
            </form>
        </section>
        <?php $skautis = appSettings(); ?>
        <section class="card">
            <h2>🔐 Připojení ke skautISu</h2>
            <p class="muted">ID aplikace přidělí správce skautISu (skaut.cz → skautIS → Aplikace). Návratová adresa aplikace: <code><?= h(baseUrl() . 'login.php') ?></code>. Stav: <?= skautisConfigured() ? '✔ nastaveno' : '✖ nenastaveno (nebo chybí PHP rozšíření SOAP)' ?>.</p>
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
                <input type="hidden" name="action" value="skautis">
                <label>ID aplikace (GUID)
                    <input type="text" name="skautis_app_id" value="<?= h((string) $skautis['skautis_app_id']) ?>" maxlength="36" placeholder="12345678-1234-1234-1234-123456789abc">
                </label>
                <div class="policy">
                    <label class="check"><input type="radio" name="skautis_test" value="1"<?= $skautis['skautis_test'] ? ' checked' : '' ?>> Testovací skautIS (test-is.skaut.cz)</label>
                    <label class="check"><input type="radio" name="skautis_test" value="0"<?= $skautis['skautis_test'] ? '' : ' checked' ?>> Ostrý skautIS (is.skaut.cz)</label>
                </div>
                <label class="check"><input type="checkbox" name="demo_login" value="1"<?= !empty($skautis['demo_login']) ? ' checked' : '' ?>> Povolit testovací vstup bez přihlášení (v ostrém provozu vypněte)</label>
                <button class="button" type="submit">Uložit skautIS</button>
            </form>
        </section>
    <?php endif; ?>

    <?php if ($pdo && $tab === 'connections'): ?>
        <?php $cal = calendarSettings(); ?>
        <section class="card">
            <h2>📅 Google Kalendář střediskových rad</h2>
            <p class="muted">Vložte tajnou adresu ve formátu iCal: Google Kalendář → Nastavení kalendáře → Integrace kalendáře → „Tajná adresa ve formátu iCal“. Adresa se před uložením ověří. Prázdné pole kalendář odpojí.</p>
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
                <input type="hidden" name="action" value="calendar">
                <label>Adresa iCal
                    <input type="url" name="calendar_ics_url" value="<?= h($cal['calendar_ics_url']) ?>" placeholder="https://calendar.google.com/calendar/ical/.../basic.ics">
                </label>
                <label>Filtr názvu události (volitelné)
                    <input type="text" name="calendar_filter" value="<?= h($cal['calendar_filter']) ?>" placeholder="např. rada">
                </label>
                <button class="button" type="submit">Uložit a ověřit kalendář</button>
            </form>
        </section>
    <?php endif; ?>

    <?php if ($pdo && $tab === 'lookups'): ?>
        <form method="post" class="admin-form">
            <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
            <input type="hidden" name="action" value="lookups">
            <?php foreach (lookupTypes() as $type => $typeLabel): ?>
                <section class="card lookup-card">
                    <h2><?= h($typeLabel) ?></h2>
                    <p class="muted">Pořadí určují šipky. Smazáním názvu nebo zaškrtnutím „Smazat“ se položka odebere (u schůzek, které ji používají, zůstane nevyplněná).</p>
                    <div class="lookup-list" data-lookup="<?= h($type) ?>">
                        <?php foreach ($lookupData[$type] ?? [] as $row): ?>
                            <div class="lookup-row">
                                <input type="text" maxlength="100" name="lookups[<?= h($type) ?>][<?= (int) $row['id'] ?>][label]" value="<?= h($row['label']) ?>">
                                <?php if ($type === 'group'): ?><select name="lookups[group][<?= (int) $row['id'] ?>][parent]" title="Oddíl"><option value="">– oddíl –</option><?php foreach ($lookupData['unit'] ?? [] as $unit): ?><option value="<?= (int) $unit['id'] ?>"<?= (int) $row['parent_id'] === (int) $unit['id'] ? ' selected' : '' ?>><?= h($unit['label']) ?></option><?php endforeach; ?></select><?php endif; ?>
                                <button type="button" data-lookup-move="-1" title="Nahoru">▲</button>
                                <button type="button" data-lookup-move="1" title="Dolů">▼</button>
                                <label class="check"><input type="checkbox" name="lookups[<?= h($type) ?>][<?= (int) $row['id'] ?>][delete]" value="1"> Smazat</label>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <label>Přidat novou položku
                        <input type="text" maxlength="100" name="new[<?= h($type) ?>][]" placeholder="Nový název">
                    </label>
                    <?php if ($type === 'group'): ?><label>Oddíl nové družiny <select name="newparent[group][]"><option value="">–</option><?php foreach ($lookupData['unit'] ?? [] as $unit): ?><option value="<?= (int) $unit['id'] ?>"><?= h($unit['label']) ?></option><?php endforeach; ?></select></label><?php endif; ?>
                </section>
            <?php endforeach; ?>
            <?php
            $agendaRowHtml = static function (string $prefix, array $row): string {
                $level = max(1, min(MAX_ITEM_LEVEL, (int) ($row['level'] ?? 1)));
                $id = isset($row['id']) ? (string) (int) $row['id'] : '__N__';
                $base = $prefix . '[' . $id . ']';
                return '<div class="agenda-row' . ($level > 1 ? ' lvl-' . $level : '') . '" data-agenda-row>'
                    . '<button type="button" class="collapse-toggle" data-agenda-toggle title="Sbalit / rozbalit podbody">▾<span class="collapse-count"></span></button><button type="button" data-agenda-move="-1" title="Nahoru (i s podbody)">↑</button><button type="button" data-agenda-move="1" title="Dolů (i s podbody)">↓</button>'
                    . '<input type="hidden" data-level name="' . $base . '[level]" value="' . $level . '">'
                    . '<button type="button" data-agenda-indent="-1" title="Zrušit odsazení (bod)">⇤</button>'
                    . '<button type="button" data-agenda-indent="1" title="Odsadit (podbod)">⇥</button>'
                    . '<input type="text" maxlength="255" name="' . $base . '[title]" value="' . h($row['title'] ?? '') . '" placeholder="Název bodu">'
                    . '<label class="check" title="Obsah se vždy převezme z předchozího zápisu"><input type="checkbox" name="' . $base . '[carry]" value="1"' . (!empty($row['always_carry']) ? ' checked' : '') . '> Přenášet</label>'
                    . (isset($row['id']) ? '<label class="check"><input type="checkbox" name="' . $base . '[delete]" value="1"> Smazat</label>' : '<button type="button" data-agenda-remove title="Odebrat">✕</button>')
                    . '</div>';
            };
            ?>
            <section class="card lookup-card">
                <h2>Osnova zápisu (hlavní kategorie)</h2>
                <p class="muted">Pořadí měňte šipkami ↑ ↓ (podbody se přesouvají s bodem), ▾ podbody sbalí. Tlačítky ⇥ / ⇤ se bod odsadí jako podbod nebo vrátí. „Přenášet“ znamená, že se obsah bodu vždy převezme z předchozího zápisu; ostatní body se přenášejí jen když je v zápisu zaškrtnuto „Řešit na další radě“.</p>
                <div class="agenda-list" id="agenda-list">
                    <?php foreach ($agendaRows as $row): ?>
                        <?= $agendaRowHtml('agenda', $row) ?>
                    <?php endforeach; ?>
                </div>
                <button class="button secondary" type="button" id="agenda-add">+ Přidat bod</button>
                <template id="agenda-template"><?= $agendaRowHtml('agenda_new', []) ?></template>
            </section>
            <button class="button" type="submit">Uložit číselníky</button>
        </form>
        <script>
        document.addEventListener('click', function (event) {
            var button = event.target.closest('[data-lookup-move]');
            if (!button) { return; }
            var row = button.closest('.lookup-row');
            var direction = parseInt(button.dataset.lookupMove, 10);
            if (direction < 0 && row.previousElementSibling) {
                row.parentElement.insertBefore(row, row.previousElementSibling);
            } else if (direction > 0 && row.nextElementSibling) {
                row.parentElement.insertBefore(row.nextElementSibling, row);
            }
        });
        (function () {
            var list = document.getElementById('agenda-list');
            if (!list) { return; }
            var MAX = 5;
            function level(row) { return parseInt(row.querySelector('[data-level]').value, 10) || 1; }
            function setLevel(row, value) {
                row.querySelector('[data-level]').value = String(value);
                row.className = row.className.replace(/\blvl-\d+\b/g, '').trim() + (value > 1 ? ' lvl-' + value : '');
            }
            function block(row) {
                var rows = [row];
                for (var n = row.nextElementSibling; n && level(n) > level(row); n = n.nextElementSibling) { rows.push(n); }
                return rows;
            }
            function refresh() {
                var previousLevel = 0, previous = null, hideBelow = Infinity;
                Array.prototype.forEach.call(list.children, function (row) {
                    var current = Math.max(1, Math.min(level(row), previousLevel + 1, MAX));
                    if (current !== level(row)) { setLevel(row, current); }
                    previousLevel = current;
                    if (current > hideBelow) { row.hidden = true; } else { hideBelow = Infinity; row.hidden = false; }
                    var subs = block(row).length - 1;
                    var collapsed = row.classList.contains('collapsed');
                    row.classList.toggle('has-subs', subs > 0);
                    row.querySelector('.collapse-count').textContent = collapsed && subs ? '(' + subs + ')' : '';
                    if (collapsed && subs && hideBelow === Infinity) { hideBelow = current; }
                    var deepest = Math.max.apply(null, block(row).map(level));
                    row.querySelector('[data-agenda-indent="1"]').disabled = !previous || level(previous) < current || deepest + 1 > MAX;
                    row.querySelector('[data-agenda-indent="-1"]').disabled = current <= 1;
                    previous = row;
                });
            }
            document.addEventListener('click', function (event) {
                var add = event.target.closest('#agenda-add');
                if (add) {
                    var n = list.children.length + Date.now();
                    list.insertAdjacentHTML('beforeend', document.getElementById('agenda-template').innerHTML.replace(/__N__/g, String(n)));
                    list.lastElementChild.querySelector('input[type="text"]').focus();
                    refresh();
                    return;
                }
                var row = event.target.closest('[data-agenda-row]');
                if (!row) { return; }
                var toggle = event.target.closest('[data-agenda-toggle]');
                var move = event.target.closest('[data-agenda-move]');
                var indent = event.target.closest('[data-agenda-indent]');
                var remove = event.target.closest('[data-agenda-remove]');
                var rows = block(row);
                if (toggle) {
                    row.classList.toggle('collapsed');
                } else if (indent && !indent.disabled) {
                    var delta = parseInt(indent.dataset.agendaIndent, 10);
                    rows.forEach(function (r) { setLevel(r, level(r) + delta); });
                } else if (remove) {
                    row.remove();
                } else if (move) {
                    if (parseInt(move.dataset.agendaMove, 10) < 0) {
                        var before = row.previousElementSibling;
                        while (before && level(before) > level(row)) { before = before.previousElementSibling; }
                        if (before && level(before) === level(row)) {
                            rows.forEach(function (r) { list.insertBefore(r, before); });
                        }
                    } else {
                        var next = rows[rows.length - 1].nextElementSibling;
                        if (next && level(next) === level(row)) {
                            var after = block(next).pop().nextElementSibling;
                            rows.forEach(function (r) { list.insertBefore(r, after); });
                        }
                    }
                } else {
                    return;
                }
                refresh();
            });
            document.addEventListener('change', function (event) {
                var box = event.target;
                var row = box.closest && box.closest('[data-agenda-row]');
                if (!row || !/\[carry\]$/.test(box.name || '')) { return; }
                block(row).forEach(function (r) { r.querySelector('input[name$="[carry]"]').checked = box.checked; });
            });
            refresh();
        })();
        </script>
    <?php endif; ?>
    <?php if ($settings && $tab === 'permissions'): ?>
        <form method="post" class="admin-form">
            <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
            <input type="hidden" name="action" value="permissions">

            <?php foreach ($modules as $key => $module): ?>
                <?php $setting = $settings[$key]; ?>
                <section class="card module-card" data-module="<?= h($key) ?>">
                    <h2><span aria-hidden="true"><?= $module['icon'] ?></span> <?= h($module['title']) ?></h2>
                    <p class="muted"><?= h($module['description']) ?></p>

                    <?php if ($key === 'admin'): ?>
                        <p class="muted">Do administrace mají vždy přístup správci uvedení v <code>admin_usernames</code> v <code>config.php</code> a vybrané role níže.</p>
                    <?php else: ?>
                        <div class="policy">
                            <label class="check"><input type="radio" name="policy[<?= h($key) ?>]" value="all"<?= $setting['policy'] === 'all' ? ' checked' : '' ?>> Každý přihlášený</label>
                            <label class="check"><input type="radio" name="policy[<?= h($key) ?>]" value="roles"<?= $setting['policy'] === 'roles' ? ' checked' : '' ?>> Jen vybrané role</label>
                        </div>
                    <?php endif; ?>

                    <div class="role-grid">
                        <?php foreach ($known as $roleKey => $role): ?>
                            <label class="check role-option">
                                <input type="checkbox" name="roles[<?= h($key) ?>][]" value="<?= h($roleKey) ?>"<?= in_array((string) $roleKey, $setting['roles'], true) ? ' checked' : '' ?>>
                                <span><?= h($role['label'] ?: $roleKey) ?><small><?= h($roleKey) ?></small></span>
                            </label>
                        <?php endforeach; ?>
                        <?php if (!$known): ?>
                            <p class="muted">Zatím se nikdo přes skautIS nepřihlásil, takže nejsou známé žádné role. Klíč role můžete zadat ručně.</p>
                        <?php endif; ?>
                    </div>

                    <label>Další role (klíče oddělené čárkou)
                        <input type="text" name="extra_roles[<?= h($key) ?>]" placeholder="např. vedouciStrediska">
                    </label>
                </section>
            <?php endforeach; ?>

            <button class="button" type="submit">Uložit oprávnění</button>
        </form>

        <section class="card">
            <h2>Vaše aktuální role</h2>
            <p>Klíč role, podle kterého se přiděluje přístup: <code><?= h($currentUser['role_key'] ?: '—') ?></code> (<?= h($currentUser['role_name'] ?: 'bez role') ?>)</p>
            <p class="muted">Seznam rolí se plní průběžně z přihlášení uživatelů přes skautIS. Má-li někdo přistupovat s rolí, která se ještě nepřihlásila, zadejte její klíč ručně.</p>
        </section>
    <?php endif; ?>
</main>
<?php renderFooter(); ?>
