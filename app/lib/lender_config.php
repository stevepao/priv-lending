<?php

declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'env.php';

if (!defined('LENDER_NAME')) {
    define('LENDER_NAME', trim((string) env('LENDER_NAME', '')));
}
if (!defined('LENDER_ADDRESS')) {
    define('LENDER_ADDRESS', trim((string) env('LENDER_ADDRESS', '')));
}
if (!defined('LENDER_CITY')) {
    define('LENDER_CITY', trim((string) env('LENDER_CITY', '')));
}
if (!defined('LENDER_STATE')) {
    define('LENDER_STATE', trim((string) env('LENDER_STATE', '')));
}
if (!defined('LENDER_ZIP')) {
    define('LENDER_ZIP', trim((string) env('LENDER_ZIP', '')));
}
if (!defined('LENDER_PHONE')) {
    define('LENDER_PHONE', trim((string) env('LENDER_PHONE', '')));
}
if (!defined('LENDER_EMAIL')) {
    define('LENDER_EMAIL', trim((string) env('LENDER_EMAIL', '')));
}
