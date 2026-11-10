# Zápisy ze střediskové rady

Jednoduchá webová aplikace v PHP pro vytvoření, uložení a tisk zápisů ze
střediskové rady. Zápisy ukládá do MySQL. Konkrétní Word šablona v repozitáři
nebyla přiložena, proto aplikace používá běžnou strukturu zápisu (účast,
program, průběh a závěry, úkoly a termín dalšího jednání).

## Nasazení na PHP/MySQL hosting

1. Nahrajte `index.php` a `style.css` do webového prostoru hostingu.
2. V administraci hostingu vytvořte MySQL databázi a uživatele s oprávněním
   vytvářet a upravovat tabulky.
3. Importujte soubor `schema.sql` do této databáze (například přes phpMyAdmin).
4. Zkopírujte `config.example.php` na `config.php` a doplňte údaje databáze.
   `config.php` se neukládá do Gitu. Alternativně nastavte proměnné prostředí
   `DB_HOST`, `DB_NAME`, `DB_USER` a `DB_PASSWORD`.
5. Otevřete adresu aplikace v prohlížeči. Hosting musí mít PHP s rozšířením
   PDO MySQL.

Zápis lze po uložení otevřít v seznamu posledních zápisů a vytisknout tlačítkem
**Tisknout** (v prohlížeči pak lze zvolit uložení do PDF). Pro produkční
nasazení používejte HTTPS a omezte přístup k aplikaci podle potřeb střediska.
