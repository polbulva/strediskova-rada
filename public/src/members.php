<?php

/** Zjednoduší text pro porovnání názvů rolí (bez diakritiky, mezer a velikosti písmen). */
function normalizeRoleName(string $text): string
{
    $text = mb_strtolower(trim($text));
    $text = strtr($text, [
        'á' => 'a', 'č' => 'c', 'ď' => 'd', 'é' => 'e', 'ě' => 'e', 'í' => 'i', 'ň' => 'n', 'ó' => 'o',
        'ř' => 'r', 'š' => 's', 'ť' => 't', 'ú' => 'u', 'ů' => 'u', 'ý' => 'y', 'ž' => 'z',
    ]);
    return preg_replace('/[^a-z0-9]+/', '', $text) ?? '';
}

/**
 * Obsazení rolí v jednotce ze skautISu (UserManagement/UserRoleALLUnit) s rolí člena rady (výchozí „Aktivní činovník“, tedy i „Středisko: aktivní činovník“).
 *
 * @return array{members: array<int, array{user_id: int, name: string, role_name: string}>, seen: string[]}
 */
function skautisCouncilMembers(SkautisClient $client, int $unitId, string $token, array $roleNames): array
{
    $wanted = array_values(array_filter(array_map('normalizeRoleName', array_filter($roleNames, static fn ($r): bool => !ctype_digit((string) $r))), static fn (string $r): bool => $r !== ''));
    $wantedIds = array_map('strval', array_filter($roleNames, static fn ($r): bool => ctype_digit((string) $r)));
    $members = [];
    $seen = [];
    $matchedRows = 0;
    $fallbackReason = '';
    // role se ověřuje podle ID role z katalogu; textové názvy jsou jen záložní způsob
    $matchRole = static function (string $label, int $roleId) use ($wanted, $wantedIds): bool {
        if ($roleId > 0 && in_array((string) $roleId, $wantedIds, true)) {
            return true;
        }
        $normalized = normalizeRoleName($label);
        foreach ($wanted as $candidate) {
            if (str_contains($normalized, $candidate)) {
                return true;
            }
        }
        return false;
    };

    try {
        $rows = $client->call('UserManagement', 'UserRoleALLUnit', ['ID_Unit' => $unitId], $token);
        $rows = $rows instanceof stdClass ? [$rows] : (is_array($rows) ? $rows : []);
        foreach ($rows as $row) {
            $label = trim((string) ($row->Role ?? ''));
            $seen[$label] = $label;
            $personId = (int) ($row->ID_Person ?? 0);
            $userKey = $personId > 0 ? $personId : -((int) ($row->ID_User ?? 0));
            $name = trim((string) ($row->Person ?? ''));
            if ($matchRole($label, (int) ($row->ID_Role ?? 0)) && $userKey !== 0 && $name !== '') {
                $matchedRows++;
                $members[$userKey] = ['user_id' => $userKey, 'name' => $name, 'role_name' => $label];
            }
        }
    } catch (SkautisException $exception) {
        $fallbackReason = $exception->getMessage();
        $users = $client->call('UserManagement', 'UserAll', ['ID_Unit' => $unitId], $token);
        $users = $users instanceof stdClass ? [$users] : (is_array($users) ? $users : []);
        foreach ($users as $userRow) {
            $userId = (int) ($userRow->ID ?? 0);
            if ($userId <= 0) {
                continue;
            }
            $roles = $client->call('UserManagement', 'UserRoleAll', ['ID_User' => $userId, 'IsActive' => true], $token);
            $roles = $roles instanceof stdClass ? [$roles] : (is_array($roles) ? $roles : []);
            foreach ($roles as $role) {
                if ((int) ($role->ID_Unit ?? 0) !== $unitId) {
                    continue;
                }
                $label = trim((string) ($role->DisplayName ?? $role->Role ?? ''));
                $seen[$label] = $label;
                $name = trim((string) ($userRow->DisplayName ?? ''));
                if ($matchRole($label, (int) ($role->ID_Role ?? 0)) && $name !== '') {
                    $members[$userId] = ['user_id' => $userId, 'name' => $name, 'role_name' => $label];
                }
            }
        }
    }
    return ['members' => array_values($members), 'seen' => array_values($seen), 'rows' => $matchedRows, 'fallback' => $fallbackReason];
}

/**
 * Katalog rolí ze skautISu (UserManagement/RoleAll) podle typu jednotky a jejich uložení mezi známé role.
 *
 * @param string[] $unitTypes např. stredisko, oddil, druzina
 */
function syncCatalogRoles(PDO $pdo, array $user, array $unitTypes): int
{
    if (($user['type'] ?? '') !== 'skautis' || empty($user['token'])) {
        throw new SkautisException('Role lze načíst jen při přihlášení přes skautIS.');
    }
    $client = skautisClient();
    $count = 0;
    $fetched = [];
    foreach ($unitTypes as $unitType) {
        $rows = $client->call('UserManagement', 'RoleAll', ['ID_UnitType' => $unitType], (string) $user['token']);
        $rows = $rows instanceof stdClass ? [$rows] : (is_array($rows) ? $rows : []);
        foreach ($rows as $row) {
            $key = trim((string) ($row->ID ?? ''));
            $label = trim((string) ($row->DisplayName ?? ''));
            if ($key === '' || !empty($row->IsHidden)) {
                continue;
            }
            rememberRole($pdo, $key, $label);
            $fetched[$key] = true;
            $count++;
        }
    }
    if ($fetched) {
        // v seznamu zůstanou jen role z katalogu
        $in = implode(',', array_fill(0, count($fetched), '?'));
        $pdo->prepare("DELETE FROM known_roles WHERE role_key NOT IN ($in)")
            ->execute(array_map('strval', array_keys($fetched)));
    }
    return $count;
}

/** Názvy rolí, které dělají ze člena člena rady (nastavení v administraci; výchozí je council_role z konfigurace). @return string[] */
function councilRoleNames(PDO $pdo): array
{
    try {
        $statement = $pdo->prepare("SELECT setting_value FROM app_settings WHERE setting_key = 'council_roles'");
        $statement->execute();
        $stored = json_decode((string) $statement->fetchColumn(), true);
        if (is_array($stored)) {
            $names = array_values(array_filter(array_map(static fn ($v): string => trim((string) $v), $stored), static fn (string $v): bool => $v !== ''));
            if ($names) {
                return $names;
            }
        }
    } catch (Throwable $exception) {
        // nastavení ještě není uloženo
    }
    return [(string) (appConfig()['council_role'] ?? 'Aktivní činovník')];
}

function saveCouncilRoleNames(PDO $pdo, array $names): void
{
    $clean = array_values(array_unique(array_filter(array_map(static fn ($v): string => mb_substr(trim((string) $v), 0, 255), $names), static fn (string $v): bool => $v !== '')));
    $pdo->prepare(
        "INSERT INTO app_settings (setting_key, setting_value) VALUES ('council_roles', :v)
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)"
    )->execute(['v' => json_encode($clean, JSON_UNESCAPED_UNICODE)]);
}

/** Nahradí uložený seznam členů rady aktuálními daty ze skautISu; vrací jejich počet. */
function syncCouncilMembers(PDO $pdo, array $user): array
{
    if (($user['type'] ?? '') !== 'skautis' || empty($user['token']) || empty($user['unit_id'])) {
        throw new SkautisException('Členy lze načíst jen při přihlášení přes skautIS.');
    }
    $roleNames = councilRoleNames($pdo);
    $matchNames = $roleNames;
    try {
        $labels = $pdo->query('SELECT role_key, label FROM known_roles')->fetchAll(PDO::FETCH_KEY_PAIR);
        foreach ($roleNames as $roleName) {
            if (isset($labels[$roleName]) && trim((string) $labels[$roleName]) !== '') {
                $matchNames[] = $labels[$roleName];
            }
        }
    } catch (Throwable $exception) {
        // katalog rolí není k dispozici, porovnává se jen podle zadaných hodnot
    }
    $result = skautisCouncilMembers(skautisClient(), (int) $user['unit_id'], (string) $user['token'], $matchNames);
    if (!$result['members']) {
        $seen = $result['seen'] ? ' Nalezené role: ' . implode(', ', array_slice($result['seen'], 0, 15)) . '.' : '';
        throw new SkautisException('Ve skautISu nebyl nalezen žádný člen s rolí „' . implode('“, „', $roleNames) . '“.' . $seen);
    }

    $pdo->beginTransaction();
    try {
        $pdo->exec('DELETE FROM council_members');
        $insert = $pdo->prepare('INSERT INTO council_members (skautis_user_id, name, role_name) VALUES (:id, :name, :role)');
        foreach ($result['members'] as $member) {
            $insert->execute(['id' => $member['user_id'], 'name' => mb_substr($member['name'], 0, 255), 'role' => mb_substr($member['role_name'], 0, 255)]);
        }
        $pdo->commit();
    } catch (Throwable $exception) {
        $pdo->rollBack();
        throw $exception;
    }
    return ['members' => count($result['members']), 'rows' => max($result['rows'], count($result['members'])), 'fallback' => $result['fallback']];
}

/** @return array<int, array{name: string, role_name: string, synced_at: string}> */
function loadCouncilMembers(PDO $pdo): array
{
    try {
        return $pdo->query('SELECT name, role_name, synced_at FROM council_members ORDER BY name')->fetchAll();
    } catch (Throwable $exception) {
        return [];
    }
}

/** Obsazení funkcí v jednotce ze skautISu (OrganizationUnit/FunctionAll); nahradí uložený seznam a vrátí jeho počet. */
function syncUnitFunctions(PDO $pdo, array $user): int
{
    if (($user['type'] ?? '') !== 'skautis' || empty($user['token']) || empty($user['unit_id'])) {
        throw new SkautisException('Funkce lze načíst jen při přihlášení přes skautIS.');
    }
    $rows = fetchUnitFunctionRows((int) $user['unit_id'], (string) $user['token']);
    $rows = $rows instanceof stdClass ? [$rows] : (is_array($rows) ? $rows : []);
    $toDate = static fn ($value): ?string => $value ? substr((string) $value, 0, 10) : null;

    $pdo->beginTransaction();
    try {
        $pdo->exec('DELETE FROM unit_functions');
        $insert = $pdo->prepare(
            'INSERT INTO unit_functions (skautis_function_id, person_name, function_name, unit_name, valid_from, valid_to)
             VALUES (:id, :person, :function, :unit, :from, :to)
             ON DUPLICATE KEY UPDATE person_name = VALUES(person_name)'
        );
        $count = 0;
        foreach ($rows as $row) {
            $insert->execute([
                'id' => (int) ($row->ID ?? 0),
                'person' => mb_substr(trim((string) ($row->Person ?? '')), 0, 255),
                'function' => mb_substr(trim((string) ($row->FunctionType ?? '')), 0, 255),
                'unit' => mb_substr(trim((string) ($row->Unit ?? '')), 0, 255),
                'from' => $toDate($row->ValidFrom ?? null),
                'to' => $toDate($row->ValidTo ?? null),
            ]);
            $count++;
        }
        $pdo->commit();
    } catch (Throwable $exception) {
        $pdo->rollBack();
        throw $exception;
    }
    return $count;
}

/** @return array<int, array{person_name: string, function_name: string, unit_name: string, synced_at: string}> */
function loadUnitFunctions(PDO $pdo): array
{
    try {
        return $pdo->query('SELECT person_name, function_name, unit_name, synced_at FROM unit_functions ORDER BY function_name, person_name')->fetchAll();
    } catch (Throwable $exception) {
        return [];
    }
}
/**
 * Funkce jednotky: nejdřív FunctionAll (úplný přehled); když ji aplikace nemá povolenou,
 * použije se veřejná FunctionAllRegistry (statutární zástupce, asistent, kontaktní osoba).
 *
 * @return stdClass[]
 */
function fetchUnitFunctionRows(int $unitId, string $token): array
{
    $client = skautisClient();
    try {
        $rows = $client->call('OrganizationUnit', 'FunctionAll', ['ID_Unit' => $unitId], $token);
        return $rows instanceof stdClass ? [$rows] : (is_array($rows) ? $rows : []);
    } catch (SkautisException $exception) {
        $primary = $exception;
    }

    $kinds = [
        'ReturnStatutory' => 'Statutární zástupce',
        'ReturnAssistant' => 'Asistent',
        'ReturnContact' => 'Kontaktní osoba',
    ];
    $result = [];
    try {
        foreach ($kinds as $flag => $label) {
            $arguments = ['ID_Unit' => $unitId, 'ReturnStatutory' => false, 'ReturnAssistant' => false, 'ReturnContact' => false, $flag => true];
            $rows = $client->call('OrganizationUnit', 'FunctionAllRegistry', $arguments, $token);
            foreach ($rows instanceof stdClass ? [$rows] : (is_array($rows) ? $rows : []) as $row) {
                $person = trim((string) ($row->Person ?? ''));
                if ($person !== '') {
                    $result[] = (object) ['ID' => crc32($label . '|' . $person), 'Person' => $person, 'FunctionType' => $label];
                }
            }
        }
    } catch (SkautisException $exception) {
        throw $primary;
    }
    return $result;
}
