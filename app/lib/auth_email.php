<?php

declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'env.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'db.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'session.php';

use PHPMailer\PHPMailer\PHPMailer;

/**
 * @return list<string>
 */
function auth_allowed_emails_list(): array
{
    $raw = (string) env('AUTH_ALLOWED_EMAILS', '');
    if (trim($raw) === '') {
        return [];
    }
    $out = [];
    foreach (explode(',', $raw) as $part) {
        $n = auth_normalize_email(trim($part));
        if ($n !== null && $n !== '') {
            $out[] = $n;
        }
    }

    return array_values(array_unique($out));
}

function auth_normalize_email(string $email): ?string
{
    $t = strtolower(trim($email));
    if ($t === '') {
        return null;
    }
    if (!filter_var($t, FILTER_VALIDATE_EMAIL)) {
        return null;
    }

    return $t;
}

function auth_email_is_allowed(string $normalizedEmail): bool
{
    return in_array($normalizedEmail, auth_allowed_emails_list(), true);
}

function auth_otp_ttl_seconds(): int
{
    $v = (int) env('OTP_TTL_SECONDS', '600');
    if ($v < 60) {
        return 60;
    }
    if ($v > 3600) {
        return 3600;
    }

    return $v;
}

function auth_otp_max_per_email_per_hour(): int
{
    $v = (int) env('AUTH_OTP_MAX_PER_EMAIL_PER_HOUR', '5');
    return max(1, min(50, $v));
}

function auth_otp_max_per_ip_per_hour(): int
{
    $v = (int) env('AUTH_OTP_MAX_PER_IP_PER_HOUR', '20');
    return max(1, min(200, $v));
}

function auth_otp_min_seconds_between_requests(): int
{
    $v = (int) env('AUTH_OTP_MIN_SECONDS_BETWEEN_REQUESTS', '45');
    return max(0, min(600, $v));
}

function auth_client_ip(): string
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    if (is_string($ip) && filter_var($ip, FILTER_VALIDATE_IP)) {
        return $ip;
    }

    return '';
}

function auth_mask_email(string $normalizedEmail): string
{
    $parts = explode('@', $normalizedEmail, 2);
    if (count($parts) !== 2) {
        return '***';
    }
    [$local, $domain] = $parts;
    $len = strlen($local);
    if ($len <= 2) {
        $shown = str_repeat('*', $len);
    } else {
        $shown = substr($local, 0, 2) . str_repeat('*', max(1, $len - 2));
    }

    return $shown . '@' . $domain;
}

function auth_generate_otp_code(): string
{
    return str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
}

/**
 * @throws \PHPMailer\PHPMailer\Exception
 */
function auth_send_otp_email(string $toNormalizedEmail, string $plainCode): void
{
    $host = (string) env('SMTP_HOST', '');
    $user = (string) env('SMTP_USERNAME', '');
    $pass = (string) env('SMTP_PASSWORD', '');
    $fromAddr = (string) env('MAIL_FROM_ADDRESS', '');
    if ($host === '' || $fromAddr === '' || !filter_var($fromAddr, FILTER_VALIDATE_EMAIL)) {
        throw new MailerException('SMTP or MAIL_FROM_ADDRESS is not configured.');
    }

    $port = (int) env('SMTP_PORT', '587');
    $enc = strtolower(trim((string) env('SMTP_ENCRYPTION', 'tls')));
    $fromName = (string) env('MAIL_FROM_NAME', 'Private Lending');

    $mail = new PHPMailer(true);
    $mail->CharSet = PHPMailer::CHARSET_UTF8;
    $mail->isSMTP();
    $mail->Host = $host;
    $mail->Port = $port > 0 ? $port : 587;
    if ($user !== '') {
        $mail->SMTPAuth = true;
        $mail->Username = $user;
        $mail->Password = $pass;
    } else {
        $mail->SMTPAuth = false;
    }
    if ($enc === 'tls') {
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    } elseif ($enc === 'ssl') {
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
    } else {
        $mail->SMTPAutoTLS = false;
        $mail->SMTPSecure = '';
    }
    $mail->setFrom($fromAddr, $fromName);
    $mail->addAddress($toNormalizedEmail);
    $ttlMin = (int) round(auth_otp_ttl_seconds() / 60);
    if ($ttlMin < 1) {
        $ttlMin = 1;
    }
    $mail->Subject = 'Your sign-in code';
    $mail->Body = "Your one-time sign-in code is: {$plainCode}\n\n"
        . "It expires in about {$ttlMin} minutes. If you did not request this, you can ignore this email.\n";
    $mail->send();
}

function auth_otp_count_recent_for_email(string $email, int $hours): int
{
    $row = dbOne(
        'SELECT COUNT(*) AS c FROM login_otps WHERE email = ? AND created_at >= DATE_SUB(NOW(), INTERVAL ? HOUR)',
        [$email, max(1, $hours)]
    );
    if ($row === null) {
        return 0;
    }

    return (int) ($row['c'] ?? 0);
}

function auth_otp_count_recent_for_ip(string $ip, int $hours): int
{
    if ($ip === '') {
        return 0;
    }
    $row = dbOne(
        'SELECT COUNT(*) AS c FROM login_otps WHERE request_ip = ? AND created_at >= DATE_SUB(NOW(), INTERVAL ? HOUR)',
        [$ip, max(1, $hours)]
    );
    if ($row === null) {
        return 0;
    }

    return (int) ($row['c'] ?? 0);
}

/**
 * @return array{ok: bool, error: string|null, sent: bool}
 */
function auth_otp_request_send(string $submittedEmail): array
{
    $norm = auth_normalize_email($submittedEmail);
    if ($norm === null) {
        return ['ok' => false, 'error' => 'Enter a valid email address.', 'sent' => false];
    }
    if (auth_allowed_emails_list() === []) {
        return ['ok' => false, 'error' => 'Sign-in is not configured (no allowed emails).', 'sent' => false];
    }
    if (!auth_email_is_allowed($norm)) {
        return ['ok' => true, 'error' => null, 'sent' => false];
    }

    $ip = auth_client_ip();
    if (auth_otp_count_recent_for_email($norm, 1) >= auth_otp_max_per_email_per_hour()) {
        return ['ok' => false, 'error' => 'Too many sign-in attempts for this email. Try again later.', 'sent' => false];
    }
    if ($ip !== '' && auth_otp_count_recent_for_ip($ip, 1) >= auth_otp_max_per_ip_per_hour()) {
        return ['ok' => false, 'error' => 'Too many sign-in attempts from this network. Try again later.', 'sent' => false];
    }

    $minGap = auth_otp_min_seconds_between_requests();
    if ($minGap > 0) {
        $last = (int) ($_SESSION['auth_otp_last_request_ts'] ?? 0);
        if ($last > 0 && (time() - $last) < $minGap) {
            return ['ok' => false, 'error' => 'Please wait a moment before requesting another code.', 'sent' => false];
        }
    }

    $code = auth_generate_otp_code();
    $hash = password_hash($code, PASSWORD_DEFAULT);
    if ($hash === false) {
        return ['ok' => false, 'error' => 'Could not create a sign-in code. Try again.', 'sent' => false];
    }
    $ttl = auth_otp_ttl_seconds();
    $expires = (new DateTimeImmutable())->modify('+' . $ttl . ' seconds')->format('Y-m-d H:i:s');
    $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');

    $pdo = db();
    $pdo->beginTransaction();
    try {
        dbExec(
            'UPDATE login_otps SET consumed_at = ? WHERE email = ? AND consumed_at IS NULL',
            [$now, $norm]
        );
        dbExec(
            'INSERT INTO login_otps (email, code_hash, expires_at, consumed_at, created_at, request_ip) '
            . 'VALUES (?, ?, ?, NULL, ?, ?)',
            [$norm, $hash, $expires, $now, $ip]
        );
        auth_send_otp_email($norm, $code);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        error_log('priv-lending OTP send: ' . $e->getMessage());
        $msg = filter_var((string) env('APP_DEBUG', ''), FILTER_VALIDATE_BOOLEAN)
            ? $e->getMessage()
            : 'Could not send the sign-in email. Try again or check mail settings.';

        return ['ok' => false, 'error' => $msg, 'sent' => false];
    }

    $_SESSION['auth_otp_last_request_ts'] = time();
    $_SESSION['login_otp_pending_email'] = $norm;

    return ['ok' => true, 'error' => null, 'sent' => true];
}

/**
 * @return array{ok: bool, error: string|null}
 */
function auth_otp_verify_and_login(string $codeInput): array
{
    $email = $_SESSION['login_otp_pending_email'] ?? null;
    if (!is_string($email) || $email === '') {
        return ['ok' => false, 'error' => 'Start over and request a new code.'];
    }
    $trimCode = preg_replace('/\s+/', '', trim($codeInput)) ?? '';
    if (strlen($trimCode) < 6) {
        return ['ok' => false, 'error' => 'Enter the 6-digit code from your email.'];
    }

    $row = dbOne(
        'SELECT id, code_hash, expires_at FROM login_otps '
        . 'WHERE email = ? AND consumed_at IS NULL AND expires_at > NOW() ORDER BY id DESC LIMIT 1',
        [$email]
    );
    if ($row === null || !password_verify($trimCode, (string) ($row['code_hash'] ?? ''))) {
        return ['ok' => false, 'error' => 'That code is incorrect or expired. Request a new one.'];
    }

    $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');
    dbExec('UPDATE login_otps SET consumed_at = ? WHERE id = ?', [$now, (int) $row['id']]);

    unset($_SESSION['login_otp_pending_email'], $_SESSION['auth_otp_last_request_ts']);
    login(1);

    return ['ok' => true, 'error' => null];
}

function auth_login_cancel_pending(): void
{
    unset($_SESSION['login_otp_pending_email'], $_SESSION['auth_otp_last_request_ts']);
}
