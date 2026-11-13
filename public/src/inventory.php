<?php

/** Cena z formuláře („1 234,50“) na číslo; prázdné pole nebo neplatná hodnota = null. */
function parseInventoryPrice(string $text): ?string
{
    $text = str_replace([' ', "\xC2\xA0", ','], ['', '', '.'], trim($text));
    if ($text === '' || !is_numeric($text) || (float) $text < 0 || (float) $text > 9999999999) {
        return null;
    }
    return number_format((float) $text, 2, '.', '');
}

function formatInventoryPrice(?string $price): string
{
    return $price === null || $price === '' ? '' : number_format((float) $price, 2, ',', ' ') . ' Kč';
}

/** Inventární číslo je koncovka roku a pořadí v roce, např. 25-001. */
function isValidInventoryNumber(string $number): bool
{
    return (bool) preg_match('~^\d{2}-\d{3,6}$~', $number);
}

/** Další volné číslo v roce (rok = rok pořízení, jinak zaúčtování, jinak letošní). */
function nextInventoryNumber(PDO $pdo, ?string $acquiredOn = null, ?string $bookedOn = null): string
{
    $date = $acquiredOn ?: ($bookedOn ?: date('Y-m-d'));
    $prefix = substr($date, 2, 2);
    $statement = $pdo->prepare('SELECT COALESCE(MAX(CAST(SUBSTRING(inventory_number, 4) AS UNSIGNED)), 0) + 1 FROM inventory_items WHERE inventory_number LIKE :prefix');
    $statement->execute(['prefix' => $prefix . '-%']);
    return $prefix . '-' . str_pad((string) $statement->fetchColumn(), 3, '0', STR_PAD_LEFT);
}

/**
 * Ověří a upraví údaje z formuláře.
 *
 * @return array{0: array<string, mixed>, 1: string[]} hodnoty a chyby
 */
function parseInventoryForm(PDO $pdo, array $post): array
{
    $errors = [];
    $name = mb_substr(trim((string) ($post['name'] ?? '')), 0, 255);
    $acquired = trim((string) ($post['acquired_on'] ?? ''));
    $booked = trim((string) ($post['booked_on'] ?? ''));
    $number = trim((string) ($post['inventory_number'] ?? ''));
    $priceText = trim((string) ($post['price'] ?? ''));
    $price = parseInventoryPrice($priceText);

    if ($name === '') {
        $errors[] = 'Vyplňte název položky.';
    }
    if ($acquired !== '' && !isValidDate($acquired)) {
        $errors[] = 'Datum pořízení není platné.';
    }
    if ($booked !== '' && !isValidDate($booked)) {
        $errors[] = 'Datum zaúčtování není platné.';
    }
    if ($priceText !== '' && $price === null) {
        $errors[] = 'Cena není platné číslo.';
    }
    if ($number !== '' && !isValidInventoryNumber($number)) {
        $errors[] = 'Inventární číslo musí mít tvar RR-NNN, např. 25-001.';
    }

    return [[
        'name' => $name,
        'acquired_on' => $acquired !== '' ? $acquired : null,
        'booked_on' => $booked !== '' ? $booked : null,
        'document' => mb_substr(trim((string) ($post['document'] ?? '')), 0, 100),
        'price' => $price,
        'warehouse_id' => lookupIdOrNull($pdo, 'warehouse', $post['warehouse_id'] ?? ''),
        'inventory_number' => $number !== '' ? $number : null,
    ], $errors];
}

/** Uloží novou položku; bez zadaného čísla dostane další volné inventární číslo. */
function saveInventoryItem(PDO $pdo, array $item): string
{
    if ($item['inventory_number'] === null) {
        $item['inventory_number'] = nextInventoryNumber($pdo, $item['acquired_on'], $item['booked_on']);
    } else {
        $exists = $pdo->prepare('SELECT COUNT(*) FROM inventory_items WHERE inventory_number = :n');
        $exists->execute(['n' => $item['inventory_number']]);
        if ($exists->fetchColumn() > 0) {
            throw new RuntimeException('Inventární číslo ' . $item['inventory_number'] . ' už je použité.');
        }
    }
    $pdo->prepare('INSERT INTO inventory_items (inventory_number, name, acquired_on, booked_on, document, price, warehouse_id)
        VALUES (:inventory_number, :name, :acquired_on, :booked_on, :document, :price, :warehouse_id)')->execute($item);
    return $item['inventory_number'];
}

function loadInventoryItems(PDO $pdo, string $search = '', bool $includeDisposed = false): array
{
    $where = [];
    $params = [];
    if (!$includeDisposed) {
        $where[] = 'i.disposed_on IS NULL AND i.disposal_note = \'\'';
    }
    if ($search !== '') {
        $where[] = '(i.name LIKE :q OR i.document LIKE :q OR w.label LIKE :q OR i.inventory_number = :n)';
        $params = ['q' => '%' . addcslashes($search, '%_\\') . '%', 'n' => $search];
    }
    $statement = $pdo->prepare('SELECT i.*, w.label AS warehouse_label FROM inventory_items i LEFT JOIN lookups w ON w.id = i.warehouse_id'
        . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
        . ' ORDER BY LEFT(i.inventory_number, 2), CAST(SUBSTRING(i.inventory_number, 4) AS UNSIGNED)');
    $statement->execute($params);
    return $statement->fetchAll();
}
