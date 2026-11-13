<?php

require __DIR__ . '/src/bootstrap.php';
require __DIR__ . '/src/lib.php';
require __DIR__ . '/src/inventory.php';

$currentUser = requireModule('inventory');
$canEdit = canAccess($currentUser, 'inventory_edit');

$errors = [];
$databaseError = '';
$pdo = null;
$items = [];
$form = ['name' => '', 'acquired_on' => '', 'booked_on' => '', 'document' => '', 'price' => '', 'warehouse_id' => '', 'inventory_number' => ''];
$warehouses = [];
$suggestedNumber = '';

try {
    $pdo = databaseConnection();
} catch (Throwable $exception) {
    $databaseError = 'Databázi se nepodařilo připojit.';
}

$search = mb_substr(trim((string) ($_GET['q'] ?? '')), 0, 100);
$showDisposed = isset($_GET['disposed']);

if ($pdo && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$canEdit || !csrfValid()) {
        $errors[] = $canEdit ? 'Formulář vypršel nebo není platný. Obnovte stránku a zkuste to znovu.' : 'K úpravě inventáře nemáte oprávnění.';
    } elseif (($_POST['action'] ?? '') === 'save_item') {
        [$parsed, $formErrors] = parseInventoryForm($pdo, $_POST);
        $form = array_merge($form, array_map(static fn ($v): string => (string) ($v ?? ''), $parsed));
        $form['price'] = trim((string) ($_POST['price'] ?? ''));
        $errors = $formErrors;
        if (!$errors) {
            try {
                $number = saveInventoryItem($pdo, $parsed);
                flash('Položka byla přidána do inventáře pod číslem ' . $number . '.', 'success');
                redirect('inventory.php');
            } catch (RuntimeException $exception) {
                $errors[] = $exception->getMessage();
            } catch (Throwable $exception) {
                $errors[] = 'Položku se nepodařilo uložit. Zkontrolujte, že jsou spuštěny všechny skripty z database/migrations.';
            }
        }
    }
}

if ($pdo) {
    try {
        $items = loadInventoryItems($pdo, $search, $showDisposed);
        $warehouses = loadLookups($pdo, 'warehouse');
        $suggestedNumber = nextInventoryNumber($pdo);
    } catch (Throwable $exception) {
        $databaseError = 'Inventář nelze načíst. Zkontrolujte, že jsou spuštěny všechny skripty z database/migrations.';
    }
}

$total = array_sum(array_map(static fn (array $item): float => (float) $item['price'], $items));

renderHeader('Inventář', $currentUser);
?>
<header class="site-header">
    <div class="container">
        <p class="eyebrow">Středisková rada</p>
        <h1>Inventář střediska</h1>
        <p>Drobný hmotný majetek střediska. Inventární číslo má tvar RR-NNN (rok a pořadí, např. 25-001), sklady se spravují v Administraci → Číselníky.</p>
    </div>
</header>

<main class="container">
    <?php renderFlash(); ?>
    <?php if ($databaseError): ?><p class="message error"><?= h($databaseError) ?></p><?php endif; ?>
    <?php foreach ($errors as $error): ?><p class="message error"><?= h($error) ?></p><?php endforeach; ?>

    <?php if ($pdo && !$databaseError): ?>
        <?php if ($canEdit): ?>
            <section class="card" id="item-form">
                <h2>Přidat položku</h2>
                <form method="post">
                    <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
                    <input type="hidden" name="action" value="save_item">
                    <div class="form-grid">
                        <label>Název <span class="required">*</span>
                            <input type="text" name="name" required maxlength="255" value="<?= h($form['name']) ?>">
                        </label>
                        <label>Datum pořízení
                            <input type="date" name="acquired_on" value="<?= h($form['acquired_on']) ?>">
                        </label>
                        <label>Datum zaúčtování
                            <input type="date" name="booked_on" value="<?= h($form['booked_on']) ?>">
                        </label>
                        <label>Doklad a PD
                            <input type="text" name="document" maxlength="100" value="<?= h($form['document']) ?>">
                        </label>
                        <label>Cena (Kč)
                            <input type="text" name="price" inputmode="decimal" maxlength="20" value="<?= h($form['price']) ?>">
                        </label>
                        <label>Sklad
                            <select name="warehouse_id"><option value="">–</option><?php foreach ($warehouses as $warehouse): ?><option value="<?= (int) $warehouse['id'] ?>"<?= (string) $form['warehouse_id'] === (string) $warehouse['id'] ? ' selected' : '' ?>><?= h($warehouse['label']) ?></option><?php endforeach; ?></select>
                        </label>
                        <label>Inventární číslo
                            <input type="text" name="inventory_number" maxlength="20" pattern="\d{2}-\d{3,6}" placeholder="<?= h($suggestedNumber) ?>" value="<?= h($form['inventory_number']) ?>" title="Tvar RR-NNN; prázdné = další volné číslo v roce pořízení">
                        </label>
                    </div>
                    <button class="button" type="submit">Přidat do inventáře</button>
                </form>
            </section>
        <?php endif; ?>

        <section class="card">
            <form method="get" class="toolbar-inline">
                <label>Hledat
                    <input type="search" name="q" value="<?= h($search) ?>" placeholder="název, doklad, sklad, číslo">
                </label>
                <label class="check"><input type="checkbox" name="disposed" value="1"<?= $showDisposed ? ' checked' : '' ?>> Zobrazit i vyřazené</label>
                <button class="button secondary" type="submit">Zobrazit</button>
            </form>
            <?php if (!$items): ?>
                <p class="muted">Žádné položky k zobrazení.</p>
            <?php else: ?>
                <div class="table-wrap">
                    <table class="doc-table">
                        <thead>
                            <tr>
                                <th>Inv. č.</th><th>Název</th><th>Pořízeno</th><th>Zaúčtováno</th><th>Doklad a PD</th><th>Cena</th><th>Sklad</th><?php if ($showDisposed): ?><th>Vyřazení</th><?php endif; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($items as $item): ?>
                                <tr>
                                    <td><?= h($item['inventory_number']) ?></td>
                                    <td><?= h($item['name']) ?></td>
                                    <td><?= h(formatDate($item['acquired_on'])) ?></td>
                                    <td><?= h(formatDate($item['booked_on'])) ?></td>
                                    <td><?= h($item['document']) ?></td>
                                    <td><?= h(formatInventoryPrice($item['price'])) ?></td>
                                    <td><?= h((string) $item['warehouse_label']) ?></td>
                                    <?php if ($showDisposed): ?>
                                        <td><?= h(trim(formatDate($item['disposed_on']) . ' ' . $item['disposal_note'])) ?></td>
                                    <?php endif; ?>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <p class="muted">Položek: <?= count($items) ?> · celková cena: <?= h(formatInventoryPrice((string) $total)) ?></p>
            <?php endif; ?>
        </section>
    <?php endif; ?>
</main>
<?php renderFooter(); ?>
