<?php

declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    $savePath = session_save_path();
    $tmp = sys_get_temp_dir();
    if ($savePath === '' || !is_writable($savePath)) {
        if ($tmp !== '' && is_writable($tmp)) {
            session_save_path($tmp);
        }
    }

    $https =
        (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (isset($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443)
        || (isset($_SERVER['HTTP_X_FORWARDED_PROTO'])
            && strtolower((string) $_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https');

    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
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
        $cookieOpts = [
            'expires' => time() - 42000,
            'path' => $params['path'],
            'secure' => $params['secure'],
            'httponly' => $params['httponly'],
            'samesite' => $params['samesite'] ?? 'Lax',
        ];
        if (($params['domain'] ?? '') !== '') {
            $cookieOpts['domain'] = $params['domain'];
        }
        setcookie(session_name(), '', $cookieOpts);
    }

    session_destroy();
}
