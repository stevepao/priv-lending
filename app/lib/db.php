<?php

declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'env.php';

/**
 * Shared PDO instance for MySQL (configured from env).
 */
function db(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $host = (string) env('DB_HOST', '');
    $dbname = (string) env('DB_NAME', '');
    $user = (string) env('DB_USER', '');
    $pass = (string) env('DB_PASS', '');

    $dsn = 'mysql:host=' . $host . ';dbname=' . $dbname . ';charset=utf8mb4';

    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ];

    $pdo = new PDO($dsn, $user, $pass, $options);

    return $pdo;
}

/**
 * Run a prepared statement (INSERT/UPDATE/DELETE). Returns affected row count.
 */
function dbExec(string $sql, array $params = []): int
{
    $stmt = db()->prepare($sql);
    $stmt->execute($params);

    return $stmt->rowCount();
}

/**
 * Fetch all rows as associative arrays.
 *
 * @return list<array<string, mixed>>
 */
function dbAll(string $sql, array $params = []): array
{
    $stmt = db()->prepare($sql);
    $stmt->execute($params);

    /** @var list<array<string, mixed>> */
    return $stmt->fetchAll();
}

/**
 * Fetch the first row as an associative array, or null if no row.
 *
 * @return array<string, mixed>|null
 */
function dbOne(string $sql, array $params = []): ?array
{
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch();

    return $row === false ? null : $row;
}
