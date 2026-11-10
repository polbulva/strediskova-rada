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
