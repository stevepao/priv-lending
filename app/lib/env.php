<?php

declare(strict_types=1);

/**
 * Load key=value pairs from .env at project root into $_ENV and the process environment.
 */
function load_dotenv(?string $rootDir = null): void
{
    $root = $rootDir ?? dirname(__DIR__, 2);
    $path = $root . DIRECTORY_SEPARATOR . '.env';

    if (!is_readable($path)) {
        return;
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES);
    if ($lines === false) {
        return;
    }

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || (isset($line[0]) && $line[0] === '#')) {
            continue;
        }

        $eq = strpos($line, '=');
        if ($eq === false) {
            continue;
        }

        $key = trim(substr($line, 0, $eq));
        if ($key === '') {
            continue;
        }

        $value = trim(substr($line, $eq + 1));

        $_ENV[$key] = $value;
        putenv($key . '=' . $value);
    }
}

/**
 * @param string $key
 * @param mixed $default
 * @return mixed
 */
function env(string $key, $default = null)
{
    if (array_key_exists($key, $_ENV)) {
        return $_ENV[$key];
    }

    $value = getenv($key);
    if ($value !== false) {
        return $value;
    }

    return $default;
}

load_dotenv();
