<?php

declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    $https =
        (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (isset($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443);

    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'domain' => '',
        'secure' => $https,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    session_start();
}

function is_logged_in(): bool
{
    return (int) ($_SESSION['user_id'] ?? 0) === 1;
}

function require_login(): void
{
    if (is_logged_in()) {
        return;
    }

    header('Location: /login');
    exit;
}

function login(int $user_id): void
{
    session_regenerate_id(true);
    $_SESSION['user_id'] = $user_id;
}

function logout(): void
{
    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires' => time() - 42000,
            'path' => $params['path'],
            'domain' => $params['domain'],
            'secure' => $params['secure'],
            'httponly' => $params['httponly'],
            'samesite' => $params['samesite'] ?? 'Lax',
        ]);
    }

    session_destroy();
}
