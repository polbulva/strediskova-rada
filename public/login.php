<?php

require_once __DIR__ . '/src/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('index.php');
}

// Testovací vstup bez skautISu (jen když je výslovně zapnut v config.php).
if (isset($_POST['demo'])) {
    if (empty(appSettings()['demo_login']) || !csrfValid()) {
        flash('Testovací vstup není povolen.');
        redirect('index.php');
    }
    $options = demoRoleOptions();
    $role = (string) ($_POST['role'] ?? '');
    if (!isset($options[$role])) {
        flash('Neznámá testovací role.');
        redirect('index.php');
    }
    loginUser(demoUser($role, $options));
    redirect('portal.php');
}

// Návrat ze skautISu: token se vždy ověří přímo ve skautISu.
try {
    $login = SkautisClient::parseLoginData($_POST);
    $identity = skautisIdentity($login);
    $user = $identity + ['type' => 'skautis', 'token' => $login['token']];

    loginUser($user);
    redirect('portal.php');
} catch (SkautisException $exception) {
    flash('Přihlášení přes skautIS se nezdařilo. ' . $exception->getMessage());
    redirect('index.php');
}
