#!/usr/bin/env php
<?php

declare(strict_types=1);

if (php_sapi_name() !== 'cli') {
    fwrite(STDERR, "This script must be run from the command line.\n");
    exit(1);
}

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'db.php';

$pdo = db();

$pdo->exec(
    'CREATE TABLE IF NOT EXISTS schema_migrations (
        id INT NOT NULL AUTO_INCREMENT,
        filename VARCHAR(255) NOT NULL,
        applied_at DATETIME NOT NULL,
        PRIMARY KEY (id),
        UNIQUE KEY uq_schema_migrations_filename (filename)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
);

$migrationsDir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'migrations';
$files = is_dir($migrationsDir)
    ? (glob($migrationsDir . DIRECTORY_SEPARATOR . '*.sql') ?: [])
    : [];
sort($files, SORT_STRING);

$appliedRows = dbAll('SELECT filename FROM schema_migrations', []);
$applied = array_flip(array_column($appliedRows, 'filename'));

$pending = [];
foreach ($files as $path) {
    $filename = basename($path);
    if (!isset($applied[$filename])) {
        $pending[] = $path;
    }
}

if ($pending === []) {
    echo "Nothing to migrate\n";
    exit(0);
}

foreach ($pending as $path) {
    $filename = basename($path);
    $raw = file_get_contents($path);
    if ($raw === false) {
        throw new RuntimeException('Failed to read migration file: ' . $path);
    }

    $pdo->beginTransaction();
    try {
        $sql = trim($raw);
        if ($sql !== '') {
            foreach (array_filter(array_map('trim', explode(';', $sql))) as $statement) {
                $pdo->exec($statement);
            }
        }

        $stmt = $pdo->prepare('INSERT INTO schema_migrations (filename, applied_at) VALUES (?, ?)');
        $stmt->execute([$filename, date('Y-m-d H:i:s')]);

        $pdo->commit();
        echo 'Applied: ' . $filename . "\n";
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

exit(0);
