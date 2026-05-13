#!/usr/bin/env php
<?php

declare(strict_types=1);

if (php_sapi_name() !== 'cli') {
    fwrite(STDERR, "This script must be run from the command line.\n");
    exit(1);
}

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'db.php';

$dbName = (string) env('DB_NAME', '');
fwrite(STDOUT, 'priv-lending migrate');
if ($dbName !== '') {
    fwrite(STDOUT, ' · database ' . $dbName);
}
fwrite(STDOUT, "\n");

$pdo = db();

foreach (['SET SESSION innodb_lock_wait_timeout = 60', 'SET SESSION lock_wait_timeout = 60'] as $sessionSql) {
    try {
        $pdo->exec($sessionSql);
    } catch (Throwable $e) {
        // Older servers may not support every variable; ignore.
    }
}

$isDuplicateColumn = static function (PDOException $e): bool {
    $info = $e->errorInfo;
    if (is_array($info) && isset($info[1]) && (int) $info[1] === 1060) {
        return true;
    }

    return str_contains($e->getMessage(), 'Duplicate column name');
};

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
    $nFiles = count($files);
    if ($nFiles === 0) {
        fwrite(STDOUT, "Status: no .sql files found under migrations/. Nothing to apply.\n");
    } else {
        fwrite(
            STDOUT,
            "Status: up to date - all {$nFiles} migration file(s) in migrations/ are already recorded in schema_migrations. No SQL was executed this run.\n"
        );
    }
    exit(0);
}

fwrite(STDOUT, 'Applying ' . count($pending) . " pending migration(s)...\n");

foreach ($pending as $path) {
    $filename = basename($path);
    $raw = file_get_contents($path);
    if ($raw === false) {
        throw new RuntimeException('Failed to read migration file: ' . $path);
    }

    fwrite(STDOUT, 'File: ' . $filename . "\n");
    fflush(STDOUT);

    $pdo->beginTransaction();
    try {
        $sql = trim($raw);
        if ($sql !== '') {
            $statements = array_values(array_filter(array_map('trim', explode(';', $sql))));
            $n = count($statements);
            $si = 0;
            foreach ($statements as $statement) {
                ++$si;
                $oneLine = preg_replace('/\s+/', ' ', $statement) ?? $statement;
                if (strlen($oneLine) > 160) {
                    $oneLine = substr($oneLine, 0, 157) . '...';
                }
                fwrite(STDOUT, "  [{$si}/{$n}] {$oneLine}\n");
                fflush(STDOUT);
                try {
                    $pdo->exec($statement);
                } catch (PDOException $e) {
                    if ($isDuplicateColumn($e)) {
                        fwrite(STDOUT, "  ... skipped (column already exists)\n");
                        fflush(STDOUT);

                        continue;
                    }
                    throw $e;
                }
                fwrite(STDOUT, "  ... OK\n");
                fflush(STDOUT);
            }
        }

        $stmt = $pdo->prepare('INSERT INTO schema_migrations (filename, applied_at) VALUES (?, ?)');
        $stmt->execute([$filename, date('Y-m-d H:i:s')]);

        $pdo->commit();
        fwrite(STDOUT, 'Applied: ' . $filename . "\n");
        fflush(STDOUT);
    } catch (Throwable $e) {
        $pdo->rollBack();
        fwrite(STDERR, 'Migration failed: ' . $filename . "\n" . $e->getMessage() . "\n");
        fflush(STDERR);
        throw $e;
    }
}

fwrite(STDOUT, "Done. schema_migrations was updated for the migration(s) listed above.\n");

exit(0);
