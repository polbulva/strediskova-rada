<?php

require __DIR__ . '/src/bootstrap.php';

$user = currentUser();
$target = 'index.php';

if ($user && $user['type'] === 'skautis' && $user['token'] !== '' && skautisConfigured()) {
    $target = skautisClient()->logoutUrl($user['token']);
}

$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 3600, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
}
session_destroy();

redirect($target);
