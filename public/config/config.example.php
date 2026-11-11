<?php

return [
    // Databáze
    'host' => 'localhost',
    'database' => 'nazev_databaze',
    'username' => 'uzivatel_databaze',
    'password' => 'doplntte-heslo',

    // skautIS: ID aplikace přidělené správcem skautISu; true = testovací skautIS (test-is.skaut.cz)
    'skautis_app_id' => '',
    'skautis_test' => true,

    // Testovací vstup bez přihlášení. V ostrém provozu VYPNĚTE (false) – kdokoli by se stal administrátorem.
    'demo_login' => true,

    // Uživatelská jména ze skautISu, která mají vždy plný přístup včetně administrace.
    'admin_usernames' => [],
    // Role ze skautISu, které mají vždy plný přístup včetně administrace (název role, jak ji vidíte v hlavičce aplikace, nebo klíč role).
    'admin_roles' => ['Středisko: vedoucí/admin'],

    // Volitelně adresa aplikace (pro návrat ze skautISu), např. 'https://example.cz/rada/'.
    // Když je prázdná, zjistí se automaticky.
    'base_url' => '',

    // Google Kalendář střediskových rad: tajná adresa ve formátu iCal.
    // Google Kalendář > Nastavení kalendáře > "Tajná adresa ve formátu iCal" (https://calendar.google.com/calendar/ical/.../basic.ics).
    // Tuto adresu nikam nesdílejte, kdo ji zná, vidí kalendář.
    'calendar_ics_url' => '',

    // Volitelně: zobrazit jen události, jejichž název obsahuje tento text (např. 'rada'). Prázdné = všechny.
    'calendar_filter' => '',
];
