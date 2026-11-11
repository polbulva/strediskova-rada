<?php

const APP_VERSION = '1.0.0';

/** Novinky aplikace, nejnovější verze nahoře. Při vydání přidejte nový záznam a zvyšte APP_VERSION. */
function releaseNotes(): array
{
    return [
        [
            'version' => '1.0.0',
            'date' => '2026-11-11',
            'changes' => [
                'Přihlášení přes skautIS, rozcestník a role s oprávněními.',
                'Zápisy ze střediskových rad podle vzoru, převzetí bodů z minulého zápisu, vícestupňová osnova se šipkami a sbalováním.',
                'Osnova zápisu s typy bodů (text, výpravy, rozpis schůzek, proběhlé a chystané akce) v Administraci → Osnova zápisu.',
                'Editor poznámek s tabulkami a obrázky.',
                'Napojení na Google Kalendář, zápis z události rady.',
                'Rozpis schůzek družin po klubovnách, úprava přímo v zápisu.',
                'Výpravy po oddílech, číselníky oddílů, družin a kluboven.',
                'Podpisy zapisovatele a ověřovatele myší nebo prstem.',
                'Export zápisu do PDF vlastním písmem a logo střediska.',
            ],
        ],
    ];
}
