<?php

const DEMO_ADMIN_ROLE = 'demo_admin';
const DEMO_MEMBER_ROLE = 'demo_member';

/**
 * Registr částí aplikace. Další část přidáte sem a přístup k ní pak nastavíte v administraci.
 * policy: 'all' = každý přihlášený, 'roles' = jen vybrané role (výchozí stav, dokud admin nenastaví jinak).
 */
function appModules(): array
{
    return [
        'minutes' => [
            'title' => 'Zápisy z rady',
            'description' => 'Zobrazení zápisů ze střediskové rady a jejich stažení do PDF.',
            'icon' => '<span class="material-symbols-outlined">description</span>',
            'url' => 'minutes.php',
            'policy' => 'all',
        ],
        'minutes_edit' => [
            'title' => 'Úprava zápisů z rady',
            'description' => 'Vytváření, úprava a mazání zápisů ze střediskové rady.',
            'icon' => '<span class="material-symbols-outlined">edit</span>',
            'url' => 'minutes.php',
            'policy' => 'roles',
            'tile' => false,
        ],
        'schedule' => [
            'title' => 'Rozpis schůzek',
            'description' => 'Přehled schůzek družin v klubovnách během sezóny (jen ke čtení).',
            'icon' => '<span class="material-symbols-outlined">calendar_month</span>',
            'url' => 'schedule.php',
            'policy' => 'all',
        ],
        'schedule_edit' => [
            'title' => 'Úprava rozpisu schůzek',
            'description' => 'Přidávání, úprava, přesun a převzetí schůzek z minulé sezóny.',
            'icon' => '<span class="material-symbols-outlined">edit</span>',
            'url' => 'schedule.php',
            'policy' => 'roles',
            'tile' => false,
        ],
        'plan' => [
            'title' => 'Plán akcí',
            'description' => 'Plán akcí na rok (září–srpen), státní svátky a školní prázdniny.',
            'icon' => '<span class="material-symbols-outlined">event</span>',
            'url' => 'plan.php',
            'policy' => 'all',
        ],
        'plan_edit' => [
            'title' => 'Úprava plánu akcí',
            'description' => 'Přidávání, úprava a mazání akcí a školních prázdnin v plánu.',
            'icon' => '<span class="material-symbols-outlined">edit</span>',
            'url' => 'plan.php',
            'policy' => 'roles',
            'tile' => false,
        ],
        'admin' => [
            'title' => 'Administrace',
            'description' => 'Nastavení, které role mají přístup do jednotlivých částí aplikace.',
            'icon' => '<span class="material-symbols-outlined">settings</span>',
            'url' => 'admin.php',
            'policy' => 'roles',
        ],
    ];
}

function currentUser(): ?array
{
    $user = $_SESSION['user'] ?? null;
    if (!is_array($user)) {
        return null;
    }
    if (!empty($user['logout_at']) && $user['logout_at'] < time()) {
        unset($_SESSION['user']);
        return null;
    }
    return $user;
}

function loginUser(array $user): void
{
    session_regenerate_id(true);
    $_SESSION['user'] = $user;
}

function isSuperAdmin(?array $user): bool
{
    if (!$user) {
        return false;
    }
    if ($user['type'] === 'demo') {
        return $user['role_key'] === DEMO_ADMIN_ROLE;
    }
    $admins = array_map('mb_strtolower', array_map('strval', (array) (appConfig()['admin_usernames'] ?? [])));
    if ($user['username'] !== '' && in_array(mb_strtolower($user['username']), $admins, true)) {
        return true;
    }

    // role ze skautISu s plným přístupem (název nebo klíč role, případně „středisko: role“)
    $adminRoles = array_map(static fn ($role): string => mb_strtolower(trim((string) $role)), (array) (appConfig()['admin_roles'] ?? ['Středisko: vedoucí/admin']));
    $candidates = array_filter([
        mb_strtolower(trim((string) ($user['role_name'] ?? ''))),
        mb_strtolower(trim((string) ($user['role_key'] ?? ''))),
        mb_strtolower(trim((string) ($user['unit_name'] ?? '') . ': ' . (string) ($user['role_name'] ?? ''))),
    ], static fn (string $value): bool => $value !== '' && $value !== ':');
    return (bool) array_intersect($candidates, $adminRoles);
}

/** @return array{policy: string, roles: string[]} */
function modulePolicy(PDO $pdo, string $module): array
{
    static $cache = [];
    if (!isset($cache[$module])) {
        $statement = $pdo->prepare('SELECT policy FROM module_settings WHERE module = :module');
        $statement->execute(['module' => $module]);
        $policy = $statement->fetchColumn() ?: appModules()[$module]['policy'];

        $statement = $pdo->prepare('SELECT role_key FROM module_access WHERE module = :module');
        $statement->execute(['module' => $module]);
        $cache[$module] = ['policy' => $policy, 'roles' => $statement->fetchAll(PDO::FETCH_COLUMN)];
    }
    return $cache[$module];
}

function canAccess(?array $user, string $module, ?PDO $pdo = null): bool
{
    if (!$user || !isset(appModules()[$module])) {
        return false;
    }
    if (isSuperAdmin($user)) {
        return true;
    }

    try {
        $setting = modulePolicy($pdo ?? databaseConnection(), $module);
    } catch (Throwable $exception) {
        return false;
    }

    if ($module !== 'admin' && $setting['policy'] === 'all') {
        return true;
    }
    return $user['role_key'] !== '' && in_array($user['role_key'], $setting['roles'], true);
}

function requireLogin(): array
{
    $user = currentUser();
    if (!$user) {
        flash('Pro vstup se nejdřív přihlaste.');
        redirect('index.php');
    }
    return $user;
}

function requireModule(string $module): array
{
    $user = requireLogin();
    if (!canAccess($user, $module)) {
        http_response_code(403);
        renderHeader('Přístup odepřen', $user);
        echo '<main class="container"><section class="card"><h2>Přístup odepřen</h2>'
            . '<p>Vaše role nemá oprávnění k této části aplikace.</p>'
            . '<p><a class="button" href="portal.php">Zpět na rozcestník</a></p></section></main>';
        renderFooter();
        exit;
    }
    return $user;
}

function rememberRole(PDO $pdo, string $roleKey, string $label): void
{
    if ($roleKey === '') {
        return;
    }
    $pdo->prepare(
        'INSERT INTO known_roles (role_key, label) VALUES (:key, :label)
         ON DUPLICATE KEY UPDATE label = VALUES(label), last_seen_at = CURRENT_TIMESTAMP'
    )->execute(['key' => mb_substr($roleKey, 0, 100), 'label' => mb_substr($label, 0, 255)]);
}

/** @return array<int, array{role_key: string, label: string, last_seen_at: string}> */
function knownRoles(PDO $pdo): array
{
    return $pdo->query('SELECT role_key, label, last_seen_at FROM known_roles ORDER BY label, role_key')->fetchAll();
}

function demoRoleOptions(): array
{
    $options = [
        DEMO_ADMIN_ROLE => 'Administrátor (vidí a smí vše)',
        DEMO_MEMBER_ROLE => 'Člen bez speciální role',
    ];
    try {
        foreach (knownRoles(databaseConnection()) as $role) {
            $options[$role['role_key']] = $role['label'] ?: $role['role_key'];
        }
    } catch (Throwable $exception) {
        // bez databáze nabídneme jen základní role
    }
    return $options;
}

function demoUser(string $roleKey, array $options): array
{
    return [
        'type' => 'demo',
        'username' => '',
        'name' => 'Testovací uživatel',
        'role_key' => $roleKey,
        'role_name' => $options[$roleKey] ?? $roleKey,
        'unit_id' => null,
        'unit_name' => '',
        'token' => '',
        'logout_at' => null,
    ];
}
