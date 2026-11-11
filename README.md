# Zápisy ze střediskové rady

Jednoduchá webová aplikace v PHP pro vytvoření, uložení a tisk zápisů ze
střediskové rady. Zápisy ukládá do MySQL a vypadají podobně jako vzorový Word
zápis: hlavička (místo, datum, čas), docházka (přítomni, online, omluveni),
automatická osnova rady, číslované body a podbody (1., a)) s odrážkami,
usnesení s hlasováním, termín další rady a podpisy (zapsal, ověřil).

## Práce se zápisem

- **Body a podbody** – osnova se předvyplní standardními body rady. Body lze
  přidávat, odebírat, přesouvat (↑ ↓) a přepínat mezi „Bod“ a „Podbod“.
- **Poznámky** – každý řádek je odrážka, odsazení dvěma mezerami vytvoří
  podbod. Odkazy (`https://…`) se v zápisu změní na klikací.
- **Navázání na předchozí zápis** – u bodu zaškrtněte „Řešit na další radě“.
  Při vytváření nového zápisu se z vybraného předchozího zápisu (výchozí je
  poslední) přenesou tyto body včetně poznámek, označí se „Převzato z minulé
  rady“ a lze je upravit. Rovněž se předvyplní místo, čas, podepisující osoby
  a datum další rady. Číslo rady se automaticky zvýší v rámci skautského roku
  (od září).
- Uložený zápis lze **upravit**, **smazat** nebo vytisknout tlačítkem
  **Tisknout** (v prohlížeči pak lze zvolit uložení do PDF).

## PDF zápisu

Tlačítko **Stáhnout PDF** u zápisu vytvoří PDF ze stejné šablony jako náhled (logo, osnova, akce, rozpis, usnesení). Vlastní písmo nahrajte FTP do public/fonts/ pod názvy ont-regular.ttf (povinný), ont-bold.ttf, ont-italic.ttf, ont-bolditalic.ttf; musí obsahovat české znaky. Bez něj se použije DejaVu Sans. Vzhled PDF se upravuje v public/src/pdf.css. Vyžaduje PHP s rozšířeními dom, mbstring (a ideálně gd pro logo).

## Struktura projektu

```
public/                  vše, co se nahrává na FTP
  index.php              úvodní stránka s přihlášením
  login.php, logout.php  návrat ze skautISu, testovací vstup, odhlášení
  portal.php             rozcestník (dlaždice podle oprávnění role)
  minutes.php            zápisy z rady
  minutes_pdf.php        export zápisu do PDF (Dompdf)
  fonts/                 vlastní písmo pro PDF (font-regular.ttf, font-bold.ttf, …)
  admin.php              administrace oprávnění rolí
  app.js, style.css
  src/                   vendor/ (Dompdf 3.1.6, LGPL), pdf.css (vzhled PDF), minutes_document.php (společná šablona zápisu), bootstrap, skautis (klient), auth (oprávnění), layout, lib (zápisy)
  config/                config.example.php (šablona); config.php je mimo Git
database/migrations/   očíslované SQL skripty pro MySQL (spouštějí se ručně po řadě, nenahrávají se na FTP)
```

## Google Kalendář a tabulka schůzek

- **Kalendář:** v `config/config.php` nastavte `calendar_ics_url` (Google Kalendář → Nastavení kalendáře → „Tajná adresa ve formátu iCal“). Volitelně `calendar_filter` (text v názvu události). V sekci Zápisy se pak zobrazí rady z kalendáře (opakované události se rozbalují) s tlačítkem „Vytvořit zápis“; datum, čas a místo se předvyplní a zápis se k události naváže (jedna událost = jeden zápis). Kalendář se cachuje 10 minut.
- **Rozpis schůzek družin:** část „Rozpis schůzek“ (jen čtení, modul `schedule`) a „Úprava rozpisu“ (modul `schedule_edit`, oprávnění v Administraci). Rozpis je po sezónách (září–červen), pro každou klubovnu zobrazený jako školní rozpis Po–Pá (víkend se přidá, jen když v něm je schůzka). Schůzka se přidává tlačítkem + u dne, upravuje kliknutím a mezi dny/klubovnami se přesouvá přetažením. Do prázdné sezóny lze rozpis převzít z předchozí. Rozpis se zobrazuje v každém zápisu podle sezóny zápisu; kdo má oprávnění k úpravě rozpisu, může ho upravit (+, kliknutí, přetažení) přímo v zápisu bez ztráty rozepsaného textu.
- **Databáze:** viz skript `003_kalendar_a_schuzky.sql` v části Nasazení. Kalendář a skautIS se nastavují v Administraci → Připojení, číselníky (kategorie, klubovny) v Administraci → Číselníky.
- **Převzetí z minulého zápisu:** nový zápis automaticky vychází z posledního předchozího zápisu (u rady z kalendáře z posledního před jejím datem). Body označené „Řešit na další radě“ přenesou obsah. Hlavní body osnovy se upravují v Administraci → Osnova zápisu (skript `005_akce_osnova.sql`).
- **Výpravy, oddíly a družiny:** v Administraci → Číselníky se spravují Oddíly a Družiny (družina patří k oddílu; později se načtou ze skautISu). V zápisu se výpravy zadávají tlačítkem „+ Přidat výpravu“ (oddíl, kdy, družina, kam, kdo vede) a pod bodem „Výpravy“ se zobrazí tabulka pro každý oddíl. Oddíl má v číselníku předvolenou klubovnu. U výpravy se po výběru oddílu nabízejí jen družiny tohoto oddílu; v dialogu schůzky se podle klubovny filtruje nabídka družin (a po zadání družiny se doplní klubovna). Názvy družin se nabízejí i při zadávání schůzky v rozpisu (skript `008_vypravy_oddily.sql`).
- **Typy bodů a editor:** v Administraci → Osnova zápisu má každý bod typ (Text, Výpravy, Rozpis schůzek, Proběhlé akce, Chystané akce). Výpravy se pak zadávají přímo v bodu typu „Výpravy“ a tabulky/rozpis/akce se vloží do zápisu na místo takového bodu. Poznámky bodů se píší v editoru (tučné, seznamy a podbody, odkazy, tabulky, obrázky vložené tlačítkem nebo Ctrl+V); HTML se na serveru čistí (`src/richtext.php`, vyžaduje PHP rozšíření dom). Skript `009_typy_bodu.sql`.
- **Akce:** v zápisu se tlačítkem „+ Přidat akci“ zadávají chystané akce (název, kdy, čas, patron, poznámky). Akce s datem před radou se v dalším zápisu nabídnou jako proběhlé s polem pro vyhodnocení; po uložení se už znovu nezobrazí.

## Přihlášení a oprávnění

- **skautIS** – aplikace používá vlastní lehký klient podle protokolu knihovny
  [skaut/Skautis](https://github.com/skaut/Skautis) (nepotřebuje Composer ani PHP 8.4,
  jen rozšíření `soap`). Přihlášení přesměruje na skautIS, ten se vrátí na `login.php`,
  token se ověří voláním `UserManagement` (`UserDetail`, `UserRoleAll`) a uloží se
  zvolená role.
- **Testovací vstup** – dokud nemáte přidělené ID aplikace (connect), nastavte
  v `config.php` `'demo_login' => true`. Na úvodní stránce pak půjde vstoupit bez
  přihlášení za zvolenou roli (administrátor / člen / role, která se už přihlásila).
  V ostrém provozu `demo_login` **vypněte**.
- **Rozcestník** zobrazuje jen části, na které má aktuální role oprávnění.
- **Administrace** (`admin.php`) – pro každou část nastavíte „každý přihlášený“
  nebo „jen vybrané role“. Role se nabízejí podle toho, kdo se přes skautIS
  přihlásil; klíč role lze zadat i ručně. Do administrace mají vždy přístup
  uživatelé uvedení v `admin_usernames` v `config.php` (první správce) a testovací
  administrátor. Nové části aplikace se přidávají do `appModules()` v `src/auth.php`.
- Klíč role se bere z pole `Role` (jinak `ID_Role`) z `UserRoleAll`; po prvním přihlášení
  ho uvidíte v administraci v sekci „Vaše aktuální role“.

## Nasazení na PHP/MySQL hosting

1. Obsah adresáře `public/` (včetně skrytých souborů `.htaccess`) nahrajte přes
   FTP do kořene webu (např. `www/` nebo `public_html/`). Soubory `.htaccess`
   v `src/` a `config/` zakazují jejich přímé otevření z prohlížeče (platí pro
   Apache).
2. V administraci hostingu vytvořte MySQL databázi a uživatele s oprávněním
   vytvářet a upravovat tabulky.
3. Spusťte skripty z `database/migrations/` v pořadí podle čísla (001, 002, 003 ...), například přes
   phpMyAdmin (záložka Import). Skripty jsou opakovatelné: už provedené změny přeskočí, takže je lze
   bezpečně spustit znovu. Po každé aktualizaci aplikace spusťte nové skripty s vyšším číslem.
   Starší tabulka `meeting_minutes` z první verze se již nepoužívá.
4. Zkopírujte `config/config.example.php` na `config/config.php` a doplňte údaje
   databáze, `skautis_app_id`, `admin_usernames` a podle potřeby `demo_login`.
   `public/config/config.php` je v `.gitignore` a neukládá se do Gitu.
   Alternativně lze databázi nastavit proměnnými prostředí `DB_HOST`, `DB_NAME`,
   `DB_USER` a `DB_PASSWORD` (a `SKAUTIS_APP_ID`).
5. Otevřete adresu aplikace v prohlížeči. Hosting musí mít PHP 8.0+ s rozšířeními
   PDO MySQL, mbstring a SOAP. U skautISu musíte mít u aplikace nastavenou
   návratovou adresu na `login.php` (např. `https://vase-domena.cz/login.php`).

Pro produkční nasazení používejte HTTPS.
- **Úprava zápisů:** zápisy zobrazí a stáhnou do PDF všichni s přístupem k Zápisům z rady; vytvářet, upravovat a mazat smí jen role s oprávněním Úprava zápisů z rady (Administrace, Oprávnění; ve výchozím stavu jen správce). Chystané akce mají formulář název akce, termín, garant, shrnutí.
