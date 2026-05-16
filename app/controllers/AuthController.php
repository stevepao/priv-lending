<?php

declare(strict_types=1);

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'auth_email.php';

final class AuthController
{
    public function loginForm(): void
    {
        header('Content-Type: text/html; charset=utf-8');
        $pending = $_SESSION['login_otp_pending_email'] ?? null;
        $masked = is_string($pending) && $pending !== '' ? auth_mask_email($pending) : null;
        $flash = $_SESSION['login_flash'] ?? null;
        unset($_SESSION['login_flash']);
        $flashType = is_array($flash) && isset($flash['type'], $flash['message']) && is_string($flash['type']) && is_string($flash['message'])
            ? $flash['type']
            : null;
        $flashMessage = is_array($flash) && isset($flash['message']) && is_string($flash['message']) ? $flash['message'] : null;

        render('auth_login', [
            'title' => 'Login',
            'otpStep' => $masked !== null,
            'maskedEmail' => $masked ?? '',
            'flashType' => $flashType,
            'flashMessage' => $flashMessage,
        ]);
    }

    public function requestOtp(): void
    {
        csrf_verify_or_die();
        $emailRaw = (string) ($_POST['email'] ?? '');
        $result = auth_otp_request_send($emailRaw);
        if (!$result['ok'] && ($result['error'] ?? '') !== '') {
            $_SESSION['login_flash'] = [
                'type' => 'error',
                'message' => (string) $result['error'],
            ];
        } elseif (!empty($result['sent'])) {
            $_SESSION['login_flash'] = [
                'type' => 'success',
                'message' => 'We sent a 6-digit code. Enter it below to finish signing in.',
            ];
        } else {
            $_SESSION['login_flash'] = [
                'type' => 'info',
                'message' => 'If that address is allowed, we sent a sign-in code. Check your inbox.',
            ];
        }

        header('Location: /login');
        exit;
    }

    public function verifyOtp(): void
    {
        csrf_verify_or_die();
        $code = (string) ($_POST['otp_code'] ?? '');
        $result = auth_otp_verify_and_login($code);
        if (!$result['ok']) {
            $_SESSION['login_flash'] = [
                'type' => 'error',
                'message' => (string) ($result['error'] ?? 'Sign-in failed.'),
            ];
            header('Location: /login');
            exit;
        }
        header('Location: /');
        exit;
    }

    public function cancelPending(): void
    {
        auth_login_cancel_pending();
        header('Location: /login');
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
