<?php

declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'session.php';

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
        try {
            $raw = random_bytes(32);
        } catch (Throwable $e) {
            $raw = openssl_random_pseudo_bytes(32);
            if ($raw === false) {
                $raw = hash('sha256', uniqid((string) mt_rand(), true), true);
            }
        }
        $_SESSION['csrf_token'] = bin2hex($raw);
    }

    return $_SESSION['csrf_token'];
}

function csrf_field(): string
{
    $token = csrf_token();

    return '<input type="hidden" name="csrf_token" value="'
        . htmlspecialchars($token, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
        . '">';
}

function csrf_verify_or_die(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        return;
    }

    $expected = $_SESSION['csrf_token'] ?? '';
    $provided = $_POST['csrf_token'] ?? '';

    if (!is_string($expected) || $expected === '' || !is_string($provided) || !hash_equals($expected, $provided)) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        echo "Forbidden\n";
        exit;
    }
}
