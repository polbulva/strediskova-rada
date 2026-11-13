<?php

const APP_VERSION = '1.7.0';

/** Novinky aplikace, nejnovější verze nahoře. Při vydání přidejte nový záznam a zvyšte APP_VERSION. */
function releaseNotes(): array
{
    return [
        [
            'version' => '1.7.0',
            'date' => '2026-11-13',
            'changes' => [
                'Členové střediskové rady se načítají ze skautISu (role k synchronizaci se vybírají v Administraci na záložce Členové rady) a v zápisu se jejich účast (přítomen, online, omluven) vybírá v okně s přepínači a zápis hlídá usnášeníschopnost rady (většina členů). V Administraci jde také načíst obsazení funkcí v jednotce. Katalog rolí (středisko, oddíl, družina) jde načíst ze skautISu tlačítkem v Oprávněních i na záložce Členové rady; role se v oprávněních a výběru členů rady identifikují podle ID role. V Oprávněních je dole filtr rolí, kterým se skryjí role, které se v seznamech nemají zobrazovat (migrace 017_skryte_role.sql).',
            ],
        ],
        [
            'version' => '1.6.0',
            'date' => '2026-11-12',
            'changes' => [
                'Po přihlášení se do seznamu rolí v administraci zapisují všechny aktivní role uživatele z jednotky nastavené v aplikaci (unit_number); role z jiných jednotek se do seznamu nedostanou.',
            ],
        ],
        [
            'version' => '1.5.0',
            'date' => '2026-11-11',
            'changes' => [
                'Nový vzhled podle Google Material Design 3: ikony Material Symbols, horní lišta, karty, zaoblená tlačítka s efektem, pole formulářů, záložky a dialogy, písmo Roboto.',
                'Přírodnější barevnost, skautské písmo (nadpisy Skaut Bold, text The Mix) a odsazení mezi bloky v celé aplikaci.',
                'Přehlednější zápis: podbody jsou ve výchozím stavu sbalené, tlačítka uložení zůstávají vidět dole.',
                'K nadcházejícím akcím v zápisu jde vybrat akci ze seznamu budoucích akcí a zadat nepovinný konec akce (synchronizuje se do kalendáře); poznámky ze zápisu se do kalendáře neposílají.',
                'Aplikace je omezena na jednotku podle registračního čísla (nastavení unit_number).',
                'Uživatel s více rolemi ve skautISu si v hlavičce vybere, pod jakou rolí chce aplikaci používat.',
                'Nové menu: levý panel (na malých obrazovkách rozbalovací), administrace u přihlášeného uživatele, novinky přes číslo verze v patičce.',
                'Na rozcestníku jsou dlaždice nahoře a nejbližší středisková rada pod nimi, jen se základními údaji.',
            ],
        ],
        [
            'version' => '1.4.0',
            'date' => '2026-11-11',
            'changes' => [
                'Přihlášení přes skautIS: návrat ze skautISu na úvodní stránku se zpracuje a uživatel se dostane na rozcestník.',
            ],
        ],
        [
            'version' => '1.3.0',
            'date' => '2026-11-12',
            'changes' => [
                'Plán akcí lze stáhnout jako PDF (tlačítko Stáhnout PDF).',
                'Plán akcí: synchronizace s Google Kalendářem (adresa iCal se nastavuje v Administraci → Připojení; skript 013_plan_kalendar.sql).',
                'Plán akcí: přepínač zobrazení poznámek k akcím (platí i pro PDF).',
                'Plán akcí: export PDF jen s termíny a názvy a export za kalendářní rok.',
                'Přidány jarní prázdniny 2027 pro okres Uherské Hradiště (skript 012_jarni_prazdniny_uherske_hradiste.sql).',
                'Státní svátky ČR jsou pevně dané zákonem (č. 245/2000 Sb.) a dopočítávají se automaticky včetně Velkého pátku a Velikonočního pondělí – není třeba je zadávat ani aktualizovat.',
            ],
        ],
        [
            'version' => '1.2.0',
            'date' => '2026-11-11',
            'changes' => [
                'Nová stránka Plán akcí: akce na rok (září–srpen) po měsících spolu se státními svátky a školními prázdninami.',
                'Akce přidaná v plánu se nabízí jako chystaná akce v zápisech; akce přidaná v zápisu se objeví v plánu.',
                'Nové oprávnění „Úprava plánu akcí“ (Administrace → Oprávnění). Vyžaduje spustit skript 011_plan_akci_prazdniny.sql.',
            ],
        ],
        [
            'version' => '1.1.0',
            'date' => '2026-11-11',
            'changes' => [
                'Chystané akce se přidávají tlačítkem + jako formulář: název akce, termín, garant a shrnutí. Přenos z minulých rad funguje stejně.',
                'Shrnutí akcí (chystaných i proběhlých) se píše v editoru s tabulkami a obrázky a v zápisu se zobrazuje bez tabulky, jako blok pod názvem akce. Vyžaduje spustit skript 010_akce_editor.sql.',
                'Proběhlé i chystané akce se zadávají a upravují přímo v bodech osnovy typu „Proběhlé akce“ a „Chystané akce“; akci lze přidat i mezi proběhlé.',
                'V náhledu a PDF zápisu se už nezobrazuje značka „Řešit na další radě“.',
                'Nové oprávnění „Úprava zápisů z rady“: ostatní uživatelé zápis jen zobrazí a stáhnou do PDF, upravovat nemohou. Oprávnění se nastavuje v Administraci.',
            ],
        ],
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
