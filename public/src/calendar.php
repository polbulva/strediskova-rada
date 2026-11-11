<?php

/**
 * Událost ze střediskového kalendáře (Google Kalendář přes iCal adresu).
 *
 * @return array<int, array{key: string, title: string, start: DateTimeImmutable, end: ?DateTimeImmutable, all_day: bool, location: string}>
 */
function calendarSettings(bool $reload = false): array
{
    static $cache = null;
    if ($cache !== null && !$reload) {
        return $cache;
    }

    $cache = [
        'calendar_ics_url' => trim((string) (appConfig()['calendar_ics_url'] ?? '')),
        'calendar_filter' => trim((string) (appConfig()['calendar_filter'] ?? '')),
    ];
    try {
        $rows = databaseConnection()->query(
            "SELECT setting_key, setting_value FROM app_settings WHERE setting_key IN ('calendar_ics_url', 'calendar_filter')"
        )->fetchAll(PDO::FETCH_KEY_PAIR);
        foreach ($rows as $key => $value) {
            $cache[$key] = trim((string) $value);
        }
    } catch (Throwable $exception) {
        // tabulka nemusí existovat, použije se config.php
    }
    return $cache;
}

function saveCalendarSettings(PDO $pdo, string $url, string $filter): void
{
    $statement = $pdo->prepare(
        'INSERT INTO app_settings (setting_key, setting_value) VALUES (:k, :v)
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
    );
    $statement->execute(['k' => 'calendar_ics_url', 'v' => $url]);
    $statement->execute(['k' => 'calendar_filter', 'v' => $filter]);
    calendarSettings(true);
}

function planCalendarUrl(): string
{
    try {
        $statement = databaseConnection()->prepare("SELECT setting_value FROM app_settings WHERE setting_key = 'plan_ics_url'");
        $statement->execute();
        return trim((string) $statement->fetchColumn());
    } catch (Throwable $exception) {
        return '';
    }
}

function savePlanCalendarUrl(PDO $pdo, string $url): void
{
    $pdo->prepare(
        "INSERT INTO app_settings (setting_key, setting_value) VALUES ('plan_ics_url', :v)
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)"
    )->execute(['v' => $url]);
}

/**
 * Načte akce roku z kalendáře plánu akcí (iCal) do tabulky events. Existující akce (podle UID) se aktualizují
 * jen v názvu, termínu a čase; garant a shrnutí zůstávají. Akce smazané v kalendáři se v plánu nemažou.
 *
 * @return array{added: int, updated: int}
 */
function syncPlanFromCalendar(PDO $pdo, string $season): array
{
    $url = planCalendarUrl();
    if ($url === '') {
        throw new RuntimeException('Kalendář plánu akcí není v administraci nastaven.');
    }
    [$from, $to] = seasonRange($season);
    $zone = calendarTimezone();
    $items = parseIcs(calendarHttpGet($url), new DateTimeImmutable($from . ' 00:00:00', $zone), new DateTimeImmutable($to . ' 23:59:59', $zone));

    // dřívější načtení vytvořilo kopie akcí odeslaných z aplikace (klíč s příponou @datum) – odstraní se
    $pdo->exec(
        "DELETE dup FROM events dup JOIN events orig ON dup.ical_key = CONCAT(orig.ical_key, '@', dup.event_date) AND dup.id <> orig.id
         WHERE NOT EXISTS (SELECT 1 FROM meeting_events me WHERE me.event_id = dup.id)"
    );

    $baseUids = [];
    foreach ($items as $item) {
        $base = preg_replace('~@\d{4}-\d{2}-\d{2}$~', '', $item['key']);
        $baseUids[$base] = ($baseUids[$base] ?? 0) + 1;
    }
    $find = $pdo->prepare('SELECT id FROM events WHERE ical_key = :k OR ical_key = :b LIMIT 1');
    $update = $pdo->prepare('UPDATE events SET name = :name, event_date = :date, event_time = :time WHERE id = :id');
    $insert = $pdo->prepare('INSERT INTO events (name, event_date, event_time, patron, notes, ical_key) VALUES (:name, :date, :time, \'\', :notes, :k)');
    $added = $updated = 0;
    $known = [];
    foreach ($pdo->query("SELECT name, event_date FROM events WHERE event_date BETWEEN '$from' AND '$to'")->fetchAll() as $row) {
        $known[planDuplicateKey((string) $row['name'], (string) $row['event_date'])] = true;
    }
    foreach ($items as $item) {
        $name = mb_substr($item['title'] !== '' ? $item['title'] : 'Akce', 0, 255);
        $params = ['name' => $name, 'date' => $item['start']->format('Y-m-d'), 'time' => $item['all_day'] ? null : $item['start']->format('H:i:s')];
        $base = preg_replace('~@\d{4}-\d{2}-\d{2}$~', '', $item['key']);
        $key = $baseUids[$base] === 1 ? $base : $item['key'];
        $find->execute(['k' => mb_substr($item['key'], 0, 255), 'b' => mb_substr($key, 0, 255)]);
        $id = $find->fetchColumn();
        if ($id) {
            $update->execute($params + ['id' => $id]);
            $updated++;
        } elseif (!isset($known[planDuplicateKey($name, $params['date'])])) {
            $known[planDuplicateKey($name, $params['date'])] = true;
            $insert->execute($params + ['notes' => $item['description'] !== '' ? $item['description'] : null, 'k' => mb_substr($key, 0, 255)]);
            $added++;
        }
    }
    return ['added' => $added, 'updated' => $updated];
}

/** @return array{calendar_id: string, key_json: string} */
function planGoogleSettings(): array
{
    $result = ['calendar_id' => '', 'key_json' => ''];
    try {
        $rows = databaseConnection()->query("SELECT setting_key, setting_value FROM app_settings WHERE setting_key IN ('plan_calendar_id', 'plan_sa_json')")->fetchAll(PDO::FETCH_KEY_PAIR);
        $result['calendar_id'] = trim((string) ($rows['plan_calendar_id'] ?? ''));
        $result['key_json'] = trim((string) ($rows['plan_sa_json'] ?? ''));
    } catch (Throwable $exception) {
        // tabulka nemusí existovat
    }
    return $result;
}

function planGoogleConfigured(): bool
{
    $settings = planGoogleSettings();
    return $settings['calendar_id'] !== '' && $settings['key_json'] !== '';
}

function savePlanGoogleSettings(PDO $pdo, string $calendarId, ?string $keyJson): void
{
    $statement = $pdo->prepare(
        'INSERT INTO app_settings (setting_key, setting_value) VALUES (:k, :v) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
    );
    $statement->execute(['k' => 'plan_calendar_id', 'v' => $calendarId]);
    if ($keyJson !== null) {
        $statement->execute(['k' => 'plan_sa_json', 'v' => $keyJson]);
    }
}

function base64Url(string $data): string
{
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

/** Přístupový token servisního účtu Google (JWT podpisem RS256). */
function googleAccessToken(string $keyJson): string
{
    static $cache = [];
    $hash = md5($keyJson);
    if (isset($cache[$hash])) {
        return $cache[$hash];
    }
    $key = json_decode($keyJson, true);
    if (!is_array($key) || empty($key['client_email']) || empty($key['private_key'])) {
        throw new RuntimeException('Klíč servisního účtu není platný JSON (chybí client_email nebo private_key).');
    }
    $now = time();
    $data = base64Url(json_encode(['alg' => 'RS256', 'typ' => 'JWT'])) . '.' . base64Url(json_encode([
        'iss' => $key['client_email'],
        'scope' => 'https://www.googleapis.com/auth/calendar.events',
        'aud' => 'https://oauth2.googleapis.com/token',
        'iat' => $now,
        'exp' => $now + 3000,
    ]));
    if (!function_exists('openssl_sign') || !openssl_sign($data, $signature, $key['private_key'], OPENSSL_ALGO_SHA256)) {
        throw new RuntimeException('Nepodařilo se podepsat požadavek (chybí rozšíření openssl nebo je klíč poškozený).');
    }
    [$status, $response] = googleRequest('POST', 'https://oauth2.googleapis.com/token', null, null, http_build_query([
        'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
        'assertion' => $data . '.' . base64Url($signature),
    ]));
    if ($status !== 200 || empty($response['access_token'])) {
        throw new RuntimeException('Google nepřihlásil servisní účet: ' . ($response['error_description'] ?? $response['error'] ?? 'HTTP ' . $status));
    }
    return $cache[$hash] = (string) $response['access_token'];
}

/** @return array{0: int, 1: array} */
function googleRequest(string $method, string $url, ?string $token, ?array $json, ?string $form = null): array
{
    if (!function_exists('curl_init')) {
        throw new RuntimeException('Na hostingu chybí rozšíření curl.');
    }
    $headers = [];
    $body = null;
    if ($token !== null) {
        $headers[] = 'Authorization: Bearer ' . $token;
    }
    if ($json !== null) {
        $headers[] = 'Content-Type: application/json';
        $body = json_encode($json, JSON_UNESCAPED_UNICODE);
    } elseif ($form !== null) {
        $headers[] = 'Content-Type: application/x-www-form-urlencoded';
        $body = $form;
    }
    $curl = curl_init($url);
    curl_setopt_array($curl, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
    ]);
    if ($body !== null) {
        curl_setopt($curl, CURLOPT_POSTFIELDS, $body);
    }
    $raw = curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    curl_close($curl);
    $decoded = is_string($raw) ? json_decode($raw, true) : null;
    return [$status, is_array($decoded) ? $decoded : []];
}

function googleEventsUrl(string $calendarId, string $eventId = ''): string
{
    return 'https://www.googleapis.com/calendar/v3/calendars/' . rawurlencode($calendarId) . '/events' . ($eventId !== '' ? '/' . rawurlencode($eventId) : '');
}

/** ID události v Google API z klíče (UID kalendáře), nebo prázdný řetězec u opakovaných výskytů a cizích zdrojů. */
function googleEventIdFromKey(?string $key): string
{
    return $key !== null && preg_match('~^([a-z0-9]+)@google\.com$~', $key, $m) ? $m[1] : '';
}

function googleEventBody(array $event): array
{
    $notes = trim(html_entity_decode(strip_tags(str_replace(['</p>', '</li>', '<br>', '<br/>', '<br />'], "\n", renderNotes((string) ($event['notes'] ?? '')))), ENT_QUOTES, 'UTF-8'));
    $description = trim(($event['patron'] !== '' ? 'Garant: ' . $event['patron'] . "\n" : '') . $notes);
    $body = ['summary' => (string) $event['name'], 'description' => mb_substr($description, 0, 8000)];
    $date = new DateTimeImmutable($event['event_date'], calendarTimezone());
    if (!empty($event['event_time'])) {
        $start = new DateTimeImmutable($event['event_date'] . ' ' . $event['event_time'], calendarTimezone());
        $body['start'] = ['dateTime' => $start->format('c'), 'timeZone' => 'Europe/Prague'];
        $body['end'] = ['dateTime' => $start->modify('+1 hour')->format('c'), 'timeZone' => 'Europe/Prague'];
    } else {
        $body['start'] = ['date' => $date->format('Y-m-d')];
        $body['end'] = ['date' => $date->modify('+1 day')->format('Y-m-d')];
    }
    return $body;
}

function planDuplicateKey(string $name, string $date): string
{
    return mb_strtolower(trim(preg_replace('~\s+~u', ' ', $name))) . '|' . $date;
}

/**
 * Odstraní z plánu akce se stejným názvem a datem (ponechá tu s vazbou na Google, jinak nejstarší).
 * Akce přiřazené k zápisu se nemažou. Vrací počet smazaných akcí.
 */
function dedupePlanEvents(PDO $pdo, array $events): int
{
    $groups = [];
    foreach ($events as $event) {
        $groups[planDuplicateKey((string) $event['name'], (string) $event['event_date'])][] = $event;
    }
    $inMeeting = $pdo->prepare('SELECT COUNT(*) FROM meeting_events WHERE event_id = :id');
    $delete = $pdo->prepare('DELETE FROM events WHERE id = :id');
    $removed = 0;
    foreach ($groups as $group) {
        if (count($group) < 2) {
            continue;
        }
        usort($group, static fn (array $a, array $b): int => [($a['ical_key'] ?? '') === '' ? 1 : 0, (int) $a['id']] <=> [($b['ical_key'] ?? '') === '' ? 1 : 0, (int) $b['id']]);
        foreach (array_slice($group, 1) as $extra) {
            $inMeeting->execute(['id' => $extra['id']]);
            if ((int) $inMeeting->fetchColumn() > 0) {
                continue;
            }
            try {
                deleteEventFromGoogle($extra['ical_key'] ?? null);
            } catch (Throwable $exception) {
                // Google duplicitu smaže při synchronizaci podle názvu a data
            }
            $delete->execute(['id' => $extra['id']]);
            $removed++;
        }
    }
    return $removed;
}

/** @return array<int, array{id: string, summary: string, date: string}> */
function googleCalendarItems(string $calendarId, string $token, string $from, string $to): array
{
    $items = [];
    $pageToken = '';
    $zone = calendarTimezone();
    $query = [
        'singleEvents' => 'true',
        'maxResults' => 250,
        'timeMin' => (new DateTimeImmutable($from . ' 00:00:00', $zone))->format('c'),
        'timeMax' => (new DateTimeImmutable($to . ' 23:59:59', $zone))->format('c'),
    ];
    for ($page = 0; $page < 20; $page++) {
        $params = $query + ($pageToken !== '' ? ['pageToken' => $pageToken] : []);
        [$status, $response] = googleRequest('GET', googleEventsUrl($calendarId) . '?' . http_build_query($params), $token, null);
        if ($status !== 200) {
            throw new RuntimeException('Výpis událostí z kalendáře selhal: ' . ($response['error']['message'] ?? 'HTTP ' . $status));
        }
        foreach ($response['items'] ?? [] as $item) {
            if (($item['status'] ?? '') === 'cancelled') {
                continue;
            }
            $date = substr((string) ($item['start']['date'] ?? $item['start']['dateTime'] ?? ''), 0, 10);
            $items[] = ['id' => (string) $item['id'], 'summary' => (string) ($item['summary'] ?? ''), 'date' => $date];
        }
        $pageToken = (string) ($response['nextPageToken'] ?? '');
        if ($pageToken === '') {
            break;
        }
    }
    return $items;
}

/**
 * Odešle akce roku do kalendáře plánu (nové vytvoří, existující aktualizuje). Plán v aplikaci je hlavní.
 *
 * @return array{created: int, updated: int, skipped: int, name: string, removed: int}
 */
function pushPlanToGoogle(PDO $pdo, array $events, string $from, string $to): array
{
    $settings = planGoogleSettings();
    $token = googleAccessToken($settings['key_json']);
    $created = $updated = $skipped = 0;
    [$status, $info] = googleRequest('GET', googleEventsUrl($settings['calendar_id']) . '?maxResults=1', $token, null);
    if ($status === 404) {
        throw new RuntimeException('Kalendář s tímto ID nebyl nalezen nebo není sdílený se servisním účtem.');
    }
    if ($status !== 200) {
        throw new RuntimeException('Přístup ke kalendáři selhal: ' . ($info['error']['message'] ?? 'HTTP ' . $status));
    }
    $save = $pdo->prepare('UPDATE events SET ical_key = :k WHERE id = :id');

    $appIds = [];
    foreach ($events as $event) {
        if (($id = googleEventIdFromKey($event['ical_key'] ?? null)) !== '') {
            $appIds[$id] = true;
        }
    }
    $groups = [];
    foreach (googleCalendarItems($settings['calendar_id'], $token, $from, $to) as $item) {
        $groups[planDuplicateKey($item['summary'], $item['date'])][] = $item['id'];
    }
    $removed = 0;
    $existing = [];
    foreach ($groups as $groupKey => $ids) {
        usort($ids, static fn (string $a, string $b): int => [isset($appIds[$a]) ? 0 : 1] <=> [isset($appIds[$b]) ? 0 : 1]);
        $existing[$groupKey] = $ids[0];
        foreach (array_slice($ids, 1) as $extraId) {
            [$deleteStatus] = googleRequest('DELETE', googleEventsUrl($settings['calendar_id'], $extraId), $token, null);
            if (in_array($deleteStatus, [200, 204, 410], true)) {
                $removed++;
            }
        }
    }

    foreach ($events as $event) {
        $key = (string) ($event['ical_key'] ?? '');
        $groupKey = planDuplicateKey((string) $event['name'], (string) $event['event_date']);
        if ($key === '' && isset($existing[$groupKey]) && preg_match('~^[a-z0-9]+$~', $existing[$groupKey]) && !isset($appIds[$existing[$groupKey]])) {
            $save->execute(['k' => $existing[$groupKey] . '@google.com', 'id' => $event['id']]);
            $appIds[$existing[$groupKey]] = true;
            $skipped++;
            continue;
        }
        if ($key === '') {
            $googleId = bin2hex(random_bytes(10));
            [$status, $response] = googleRequest('POST', googleEventsUrl($settings['calendar_id']), $token, googleEventBody($event) + ['id' => $googleId]);
            if ($status === 404) {
                throw new RuntimeException('Kalendář nebyl nalezen. Zkontrolujte ID kalendáře a sdílení se servisním účtem.');
            }
            if ($status === 403) {
                throw new RuntimeException('Servisní účet nemá právo zapisovat do kalendáře (sdílejte kalendář s právem „Provádět změny událostí“).');
            }
            if ($status !== 200) {
                throw new RuntimeException('Google odmítl vytvořit akci „' . $event['name'] . '“: ' . ($response['error']['message'] ?? 'HTTP ' . $status));
            }
            $save->execute(['k' => $googleId . '@google.com', 'id' => $event['id']]);
            $created++;
        } elseif (($googleId = googleEventIdFromKey($key)) !== '') {
            [$status] = googleRequest('PATCH', googleEventsUrl($settings['calendar_id'], $googleId), $token, googleEventBody($event));
            if ($status === 200) {
                $updated++;
            } else {
                $skipped++;
            }
        } else {
            $skipped++;
        }
    }
    return ['created' => $created, 'updated' => $updated, 'skipped' => $skipped, 'removed' => $removed, 'name' => (string) ($info['summary'] ?? '')];
}

function deleteEventFromGoogle(?string $key): void
{
    $googleId = googleEventIdFromKey($key);
    if ($googleId === '' || !planGoogleConfigured()) {
        return;
    }
    $settings = planGoogleSettings();
    googleRequest('DELETE', googleEventsUrl($settings['calendar_id'], $googleId), googleAccessToken($settings['key_json']), null);
}

function calendarConfigured(): bool
{
    return calendarSettings()['calendar_ics_url'] !== '';
}

function calendarTimezone(): DateTimeZone
{
    return new DateTimeZone('Europe/Prague');
}

function calendarHttpGet(string $url): string
{
    if (!preg_match('~^https://~i', $url)) {
        throw new RuntimeException('Adresa kalendáře musí začínat https://.');
    }

    if (function_exists('curl_init')) {
        $curl = curl_init($url);
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
        ]);
        $body = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);
    } else {
        $context = stream_context_create(['http' => ['timeout' => 10]]);
        $body = @file_get_contents($url, false, $context);
        $status = $body === false ? 0 : 200;
    }

    if ($body === false || $status !== 200) {
        throw new RuntimeException('Kalendář se nepodařilo stáhnout (HTTP ' . $status . ').');
    }
    return $body;
}

function icsUnescape(string $value): string
{
    return str_replace(['\\n', '\\N', '\\,', '\\;', '\\\\'], ["\n", "\n", ',', ';', '\\'], $value);
}

/** @return array{0: DateTimeImmutable, 1: bool}|null */
function icsDate(string $value, array $params): ?array
{
    $zone = calendarTimezone();
    $value = trim($value);

    if (preg_match('/^(\d{4})(\d{2})(\d{2})$/', $value, $m)) {
        return [new DateTimeImmutable("{$m[1]}-{$m[2]}-{$m[3]} 00:00:00", $zone), true];
    }
    if (!preg_match('/^(\d{4})(\d{2})(\d{2})T(\d{2})(\d{2})(\d{2})(Z?)$/', $value, $m)) {
        return null;
    }

    $text = "{$m[1]}-{$m[2]}-{$m[3]} {$m[4]}:{$m[5]}:{$m[6]}";
    try {
        if ($m[7] === 'Z') {
            return [(new DateTimeImmutable($text, new DateTimeZone('UTC')))->setTimezone($zone), false];
        }
        $source = isset($params['TZID']) ? new DateTimeZone($params['TZID']) : $zone;
        return [(new DateTimeImmutable($text, $source))->setTimezone($zone), false];
    } catch (Throwable $exception) {
        return [new DateTimeImmutable($text, $zone), false];
    }
}

/** @return array<int, array<string, array{params: array, value: string}>> */
function icsEvents(string $ics): array
{
    $ics = preg_replace("/\r?\n[ \t]/", '', $ics);
    $events = [];

    if (!preg_match_all('/BEGIN:VEVENT(.*?)END:VEVENT/s', $ics, $blocks)) {
        return [];
    }

    foreach ($blocks[1] as $block) {
        $event = [];
        foreach (preg_split('/\r?\n/', $block) as $line) {
            if (!preg_match('/^([A-Z-]+)((?:;[^:]*)?):(.*)$/', $line, $m)) {
                continue;
            }
            $params = [];
            foreach (array_filter(explode(';', ltrim($m[2], ';'))) as $part) {
                [$name, $value] = array_pad(explode('=', $part, 2), 2, '');
                $params[strtoupper($name)] = trim($value, '"');
            }
            $event[$m[1]][] = ['params' => $params, 'value' => $m[3]];
        }
        $events[] = $event;
    }

    return $events;
}

function icsRuleParts(string $rule): array
{
    $parts = [];
    foreach (explode(';', $rule) as $part) {
        [$name, $value] = array_pad(explode('=', $part, 2), 2, '');
        $parts[strtoupper($name)] = $value;
    }
    return $parts;
}

/**
 * Rozbalí opakování (DAILY, WEEKLY vč. BYDAY, MONTHLY vč. "první neděle v měsíci", YEARLY)
 * do zadaného okna. Složitější pravidla se nepodporují.
 *
 * @return DateTimeImmutable[]
 */
function expandRule(DateTimeImmutable $start, string $rule, DateTimeImmutable $from, DateTimeImmutable $to): array
{
    $rule = icsRuleParts($rule);
    $freq = $rule['FREQ'] ?? '';
    $interval = max(1, (int) ($rule['INTERVAL'] ?? 1));
    $count = isset($rule['COUNT']) ? (int) $rule['COUNT'] : null;
    $until = null;
    if (isset($rule['UNTIL'])) {
        $parsed = icsDate($rule['UNTIL'], []);
        $until = $parsed ? $parsed[0]->setTime(23, 59, 59) : null;
    }

    $days = ['MO' => 1, 'TU' => 2, 'WE' => 3, 'TH' => 4, 'FR' => 5, 'SA' => 6, 'SU' => 7];
    $names = [1 => 'monday', 2 => 'tuesday', 3 => 'wednesday', 4 => 'thursday', 5 => 'friday', 6 => 'saturday', 7 => 'sunday'];
    $byDay = array_filter(explode(',', $rule['BYDAY'] ?? ''));
    $time = $start->format('H:i:s');

    $candidates = [];
    $period = 0;
    while ($period < 1200) {
        $base = match ($freq) {
            'DAILY' => $start->modify('+' . ($period * $interval) . ' days'),
            'WEEKLY' => $start->modify('+' . ($period * $interval) . ' weeks'),
            'MONTHLY' => $start->modify('first day of this month')->modify('+' . ($period * $interval) . ' months'),
            'YEARLY' => $start->modify('+' . ($period * $interval) . ' years'),
            default => null,
        };
        if ($base === null) {
            return [];
        }
        $period++;

        $batch = [];
        if ($freq === 'WEEKLY' && $byDay) {
            $monday = $base->modify('monday this week');
            foreach ($byDay as $code) {
                $dayNumber = $days[substr($code, -2)] ?? null;
                if ($dayNumber) {
                    $batch[] = $monday->modify('+' . ($dayNumber - 1) . ' days')->setTime(...array_map('intval', explode(':', $time)));
                }
            }
        } elseif ($freq === 'MONTHLY' && $byDay) {
            foreach ($byDay as $code) {
                if (!preg_match('/^(-?\d)?(MO|TU|WE|TH|FR|SA|SU)$/', $code, $m)) {
                    continue;
                }
                $ordinal = $m[1] === '' ? 1 : (int) $m[1];
                $monthText = $base->format('F Y');
                $day = $names[$days[$m[2]]];
                $date = $ordinal > 0
                    ? new DateTimeImmutable(['', 'first', 'second', 'third', 'fourth', 'fifth'][min($ordinal, 5)] . " $day of $monthText", $start->getTimezone())
                    : new DateTimeImmutable("last $day of $monthText", $start->getTimezone());
                if ($date->format('Y-m') === $base->format('Y-m')) {
                    $batch[] = $date->setTime(...array_map('intval', explode(':', $time)));
                }
            }
        } elseif ($freq === 'MONTHLY') {
            $day = (int) $start->format('j');
            if ($day <= (int) $base->format('t')) {
                $batch[] = $base->setDate((int) $base->format('Y'), (int) $base->format('n'), $day)->setTime(...array_map('intval', explode(':', $time)));
            }
        } else {
            $batch[] = $base;
        }

        sort($batch);
        foreach ($batch as $candidate) {
            if ($candidate < $start) {
                continue;
            }
            $candidates[] = $candidate;
        }

        $last = $base;
        if (($until && $last > $until) || $last > $to->modify('+1 month')) {
            break;
        }
        if ($count !== null && count($candidates) >= $count) {
            break;
        }
    }

    $result = [];
    foreach ($candidates as $index => $candidate) {
        if ($count !== null && $index >= $count) {
            break;
        }
        if ($until && $candidate > $until) {
            break;
        }
        if ($candidate >= $from && $candidate <= $to) {
            $result[] = $candidate;
        }
    }
    return $result;
}

function parseIcs(string $ics, DateTimeImmutable $from, DateTimeImmutable $to): array
{
    $rawEvents = icsEvents($ics);
    $overrides = [];
    $masters = [];

    foreach ($rawEvents as $raw) {
        $status = strtoupper($raw['STATUS'][0]['value'] ?? '');
        $uid = $raw['UID'][0]['value'] ?? '';
        $startRaw = $raw['DTSTART'][0] ?? null;
        if ($uid === '' || !$startRaw) {
            continue;
        }
        $start = icsDate($startRaw['value'], $startRaw['params']);
        if (!$start) {
            continue;
        }
        $endRaw = $raw['DTEND'][0] ?? null;
        $end = $endRaw ? (icsDate($endRaw['value'], $endRaw['params'])[0] ?? null) : null;

        $event = [
            'uid' => $uid,
            'title' => trim(icsUnescape($raw['SUMMARY'][0]['value'] ?? '')),
            'location' => trim(icsUnescape($raw['LOCATION'][0]['value'] ?? '')),
            'description' => trim(icsUnescape($raw['DESCRIPTION'][0]['value'] ?? '')),
            'start' => $start[0],
            'end' => $end,
            'all_day' => $start[1],
            'cancelled' => $status === 'CANCELLED',
            'rule' => $raw['RRULE'][0]['value'] ?? null,
            'exdates' => [],
        ];

        foreach ($raw['EXDATE'] ?? [] as $exdate) {
            foreach (explode(',', $exdate['value']) as $value) {
                $parsed = icsDate($value, $exdate['params']);
                if ($parsed) {
                    $event['exdates'][$parsed[0]->format('Y-m-d')] = true;
                }
            }
        }

        if (isset($raw['RECURRENCE-ID'][0])) {
            $original = icsDate($raw['RECURRENCE-ID'][0]['value'], $raw['RECURRENCE-ID'][0]['params']);
            if ($original) {
                $overrides[$uid . '@' . $original[0]->format('Y-m-d')] = $event;
            }
            continue;
        }
        $masters[] = $event;
    }

    $result = [];
    $add = static function (array $event, DateTimeImmutable $start, string $originalDate) use (&$result, $from, $to): void {
        if ($event['cancelled'] || $start < $from || $start > $to) {
            return;
        }
        $end = $event['end'] ? $start->add($event['start']->diff($event['end'])) : null;
        $result[] = [
            'key' => $event['uid'] . '@' . $originalDate,
            'title' => $event['title'],
            'start' => $start,
            'end' => $end,
            'all_day' => $event['all_day'],
            'location' => $event['location'],
            'description' => $event['description'],
        ];
    };

    foreach ($masters as $event) {
        $occurrences = $event['rule'] ? expandRule($event['start'], $event['rule'], $from, $to) : [$event['start']];
        foreach ($occurrences as $occurrence) {
            $date = $occurrence->format('Y-m-d');
            if (isset($event['exdates'][$date])) {
                continue;
            }
            if (isset($overrides[$event['uid'] . '@' . $date])) {
                $override = $overrides[$event['uid'] . '@' . $date];
                $add($override, $override['start'], $date);
                continue;
            }
            $add($event, $occurrence, $date);
        }
    }

    usort($result, static fn (array $a, array $b): int => $a['start'] <=> $b['start']);
    return $result;
}

/**
 * Události kalendáře od -120 do +240 dnů. Výsledek se 10 minut cachuje v dočasném adresáři;
 * při výpadku Googlu se použije poslední stažená verze.
 *
 * @return array<int, array{key: string, title: string, start: DateTimeImmutable, end: ?DateTimeImmutable, all_day: bool, location: string}>
 */
function calendarEvents(bool $refresh = false): array
{
    $url = calendarSettings()['calendar_ics_url'];
    $cacheFile = sys_get_temp_dir() . '/strediskova-rada-cal-' . md5($url) . '.ics';

    $ics = null;
    if (!$refresh && is_file($cacheFile) && filemtime($cacheFile) > time() - 600) {
        $ics = file_get_contents($cacheFile);
    }

    if ($ics === null || $ics === false) {
        try {
            $ics = calendarHttpGet($url);
            @file_put_contents($cacheFile, $ics, LOCK_EX);
        } catch (Throwable $exception) {
            if (!is_file($cacheFile)) {
                throw $exception;
            }
            $ics = file_get_contents($cacheFile);
        }
    }

    $now = new DateTimeImmutable('now', calendarTimezone());
    $events = parseIcs($ics, $now->modify('-120 days')->setTime(0, 0), $now->modify('+240 days'));

    $filter = calendarSettings()['calendar_filter'];
    if ($filter !== '') {
        $events = array_values(array_filter(
            $events,
            static fn (array $event): bool => mb_stripos($event['title'], $filter) !== false
        ));
    }

    return $events;
}
