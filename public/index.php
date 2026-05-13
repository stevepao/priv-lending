<?php

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

echo "BOOT OK<br>";

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
echo 'PATH: ' . $path . "<br>\n";

if ($path === '/login') {

    echo "STEP 1<br>\n";

    require __DIR__ . '/../app/pages/login.php';

    echo "STEP 2<br>\n";

    exit;
}

echo "HOME OK";
