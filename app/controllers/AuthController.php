<?php

declare(strict_types=1);

final class AuthController
{
    public function loginForm(): void
    {
        header('Content-Type: text/html; charset=utf-8');
        render('auth_login', [
            'title' => 'Login',
        ]);
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
