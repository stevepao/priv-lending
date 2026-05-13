<?php

declare(strict_types=1);

final class AuthController
{
    public function loginForm(): void
    {
        $title = 'Login';
        header('Content-Type: text/html; charset=utf-8');
        echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><title>' . e($title) . '</title></head><body>';
        echo '<form method="post" action="/login">' . csrf_field() . '<button type="submit">Sign in</button></form>';
        echo '</body></html>';
    }

    public function login(): void
    {
        csrf_verify_or_die();
        login(1);
        header('Location: /');
        exit;
    }

    public function logout(): void
    {
        csrf_verify_or_die();
        logout();
        header('Location: /login');
        exit;
    }
}
