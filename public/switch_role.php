<?php

require_once __DIR__ . '/src/bootstrap.php';

$user = requireLogin();
$return = basename((string) ($_POST['return'] ?? 'portal.php'));
if (!preg_match('/^[a-z_\-]+\.php$/', $return) || !is_file(__DIR__ . '/' . $return)) {
    $return = 'portal.php';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrfValid()) {
    $roles = (array) ($user['roles'] ?? []);
    $choice = $roles[(int) ($_POST['role'] ?? -1)] ?? null;
    if (is_array($choice)) {
        $_SESSION['user'] = array_merge($user, [
            'role_key' => $choice['role_key'],
            'role_name' => $choice['role_name'],
            'unit_id' => $choice['unit_id'],
            'unit_name' => $choice['unit_name'],
            'unit_number' => $choice['unit_number'],
        ]);
        // akce dané rolí nemusí být dostupné, proto se vždy vracíme na rozcestník
        $return = 'portal.php';
    }
}

redirect($return);
