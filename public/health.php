<?php

declare(strict_types=1);

header('Content-Type: text/plain; charset=utf-8');
echo 'PHP ' . PHP_VERSION . "\n";
echo 'SAPI ' . PHP_SAPI . "\n";
echo 'extension pdo: ' . (extension_loaded('pdo') ? 'yes' : 'no') . "\n";
echo 'extension pdo_mysql: ' . (extension_loaded('pdo_mysql') ? 'yes' : 'no') . "\n";
echo "OK\n";
