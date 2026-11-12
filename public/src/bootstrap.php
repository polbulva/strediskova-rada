<?php

define('APP_ROOT', dirname(__DIR__));

/**
 * Konfigurace: config/config.php (nebo proměnné prostředí). Chybějící klíče mají bezpečné výchozí hodnoty.
 */
function appConfig(): array
{
    static $config = null;
    if ($config !== null) {
        return $config;
    }

    $file = APP_ROOT . '/config/config.php';
    $loaded = is_file($file) ? require $file : [];
    $loaded = is_array($loaded) ? $loaded : [];

    $config = $loaded + [
        'host' => getenv('DB_HOST') ?: 'localhost',
        'database' => getenv('DB_NAME') ?: '',
        'username' => getenv('DB_USER') ?: '',
        'password' => getenv('DB_PASSWORD') ?: '',
        'skautis_app_id' => getenv('SKAUTIS_APP_ID') ?: '',
        'skautis_test' => true,
        'demo_login' => false,
        'admin_usernames' => [],
        'base_url' => '',
        'calendar_ics_url' => '',
        'calendar_filter' => '',
    ];

    return $config;
}

const DB_SETTING_KEYS = ['skautis_app_id', 'skautis_test', 'demo_login'];

/** Konfigurace přepsaná hodnotami z administrace (tabulka app_settings). */
function appSettings(bool $reload = false): array
{
    static $settings = null;
    if ($settings !== null && !$reload) {
        return $settings;
    }

    $settings = appConfig();
    try {
        $in = "'" . implode("','", DB_SETTING_KEYS) . "'";
        $rows = databaseConnection()->query("SELECT setting_key, setting_value FROM app_settings WHERE setting_key IN ($in)")
            ->fetchAll(PDO::FETCH_KEY_PAIR);
        foreach ($rows as $key => $value) {
            $settings[$key] = $key === 'skautis_app_id' ? trim((string) $value) : $value === '1';
        }
    } catch (Throwable $exception) {
        // tabulka nemusí existovat, platí config.php
    }
    return $settings;
}

function saveAppSettings(PDO $pdo, array $values): void
{
    $statement = $pdo->prepare(
        'INSERT INTO app_settings (setting_key, setting_value) VALUES (:k, :v)
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
    );
    foreach ($values as $key => $value) {
        $statement->execute(['k' => $key, 'v' => is_bool($value) ? ($value ? '1' : '0') : (string) $value]);
    }
    appSettings(true);
}

const LOGO_TYPES = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/gif' => 'gif', 'image/webp' => 'webp'];

function logoDirectory(): string
{
    return dirname(__DIR__) . '/uploads';
}

function logoPath(): ?string
{
    foreach (LOGO_TYPES as $extension) {
        $path = logoDirectory() . '/logo.' . $extension;
        if (is_file($path)) {
            return $path;
        }
    }
    return null;
}

function logoUrl(): ?string
{
    $path = logoPath();
    return $path ? 'uploads/' . basename($path) . '?v=' . filemtime($path) : null;
}

function removeLogo(): void
{
    foreach (LOGO_TYPES as $extension) {
        @unlink(logoDirectory() . '/logo.' . $extension);
    }
}

/** Uloží nahrané logo; vrací chybovou zprávu nebo null. */
function saveUploadedLogo(array $file): ?string
{
    $code = $file['error'] ?? UPLOAD_ERR_NO_FILE;
    if ($code !== UPLOAD_ERR_OK) {
        return match ($code) {
            UPLOAD_ERR_NO_FILE => 'Nevybrali jste žádný soubor.',
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'Soubor je větší než limit hostingu (upload_max_filesize = ' . ini_get('upload_max_filesize') . '). Zmenšete logo.',
            UPLOAD_ERR_NO_TMP_DIR, UPLOAD_ERR_CANT_WRITE => 'Hosting nemá dočasnou složku pro nahrávání nebo do ní nelze zapisovat (kód ' . $code . ').',
            default => 'Soubor se nepodařilo nahrát (kód chyby ' . $code . ').',
        };
    }
    if ($file['size'] > 2 * 1024 * 1024) {
        return 'Logo může mít nejvýše 2 MB.';
    }
    $info = @getimagesize($file['tmp_name']);
    $extension = $info ? (LOGO_TYPES[$info['mime']] ?? null) : null;
    if (!$extension) {
        return 'Povolené formáty loga jsou PNG, JPEG, GIF a WebP.';
    }
    $directory = logoDirectory();
    if (!is_dir($directory) && !@mkdir($directory, 0755, true)) {
        return 'Složku uploads se nepodařilo vytvořit. Vytvořte ji na FTP a povolte zápis.';
    }
    if (!is_writable($directory)) {
        return 'Do složky uploads nelze zapisovat. Na FTP jí nastavte oprávnění 755 nebo 775 (případně 777).';
    }
    $htaccess = "<FilesMatch \"\\.(php|phtml|phar)$\">\n    <IfModule mod_authz_core.c>\n        Require all denied\n    </IfModule>\n    <IfModule !mod_authz_core.c>\n        Order allow,deny\n        Deny from all\n    </IfModule>\n</FilesMatch>\n";
    @file_put_contents($directory . '/.htaccess', $htaccess);
    removeLogo();
    if (!@move_uploaded_file($file['tmp_name'], $directory . '/logo.' . $extension)) {
        return 'Logo se nepodařilo uložit. Zkontrolujte oprávnění složky uploads.';
    }
    return null;
}
function h(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function databaseConnection(): PDO
{
    static $pdo = null;
    if ($pdo) {
        return $pdo;
    }

    $config = appConfig();
    if (empty($config['database']) || empty($config['username'])) {
        throw new RuntimeException('Není nastavena konfigurace databáze.');
    }

    $dsn = sprintf(
        'mysql:host=%s;dbname=%s;charset=utf8mb4',
        $config['host'] ?: 'localhost',
        $config['database']
    );

    $pdo = new PDO($dsn, $config['username'], $config['password'] ?? '', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);

    return $pdo;
}

function isHttps(): bool
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
}

/** Absolutní adresa aplikace (s lomítkem na konci), např. https://example.cz/rada/ */
function baseUrl(): string
{
    $configured = trim((string) appConfig()['base_url']);
    if ($configured !== '') {
        return rtrim($configured, '/') . '/';
    }

    $dir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
    return (isHttps() ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . $dir . '/';
}

function startSession(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    session_set_cookie_params([
        'httponly' => true,
        'secure' => isHttps(),
        'samesite' => 'Lax',
    ]);
    session_start();
}

function csrfToken(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrfValid(): bool
{
    return hash_equals(csrfToken(), (string) ($_POST['csrf_token'] ?? ''));
}

function redirect(string $url): never
{
    header('Location: ' . $url);
    exit;
}

function flash(?string $message = null, string $type = 'error'): ?array
{
    if ($message !== null) {
        $_SESSION['flash'] = ['message' => $message, 'type' => $type];
        return null;
    }
    $flash = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $flash;
}

startSession();

require __DIR__ . '/skautis.php';
require __DIR__ . '/auth.php';
require __DIR__ . '/layout.php';

/** Ikona Material Symbols (písmo z Google Fonts); název podle https://fonts.google.com/icons. */
function icon(string $name): string
{
    return '<span class="material-symbols-outlined" aria-hidden="true">' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '</span>';
}
