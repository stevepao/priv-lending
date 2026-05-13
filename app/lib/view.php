<?php

declare(strict_types=1);

function e(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

/**
 * Load a PHP view from app/views/. Keys in $data are extracted as local variables for the template.
 *
 * @param array<string, mixed> $data
 */
function render(string $view, array $data = []): void
{
    extract($data);
    require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'views' . DIRECTORY_SEPARATOR . $view . '.php';
}
