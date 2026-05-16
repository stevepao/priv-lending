<?php

declare(strict_types=1);

if (!extension_loaded('pdo') || !extension_loaded('pdo_mysql')) {
    http_response_code(503);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'This app needs the pdo and pdo_mysql PHP extensions enabled for the web site.' . "\n";
    exit;
}

$projectRoot = dirname(__DIR__);

require_once $projectRoot . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'env.php';
$autoload = $projectRoot . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';
if (is_readable($autoload)) {
    require_once $autoload;
}
$showErrorDetail = filter_var((string) env('APP_DEBUG', ''), FILTER_VALIDATE_BOOLEAN);
if ($showErrorDetail) {
    ini_set('display_errors', '1');
    ini_set('display_startup_errors', '1');
    ini_set('html_errors', '0');
    error_reporting(E_ALL);
}

require_once $projectRoot . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'session.php';
bootstrap_session();

require_once $projectRoot . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'csrf.php';
require_once $projectRoot . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'security_headers.php';
security_headers();

require_once $projectRoot . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'view.php';
require_once $projectRoot . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'db.php';

require_once $projectRoot . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'lending_domain.php';

$c = $projectRoot . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'controllers' . DIRECTORY_SEPARATOR;
require_once $c . 'AuthController.php';
require_once $c . 'BankController.php';
require_once $c . 'BorrowersController.php';
require_once $c . 'CashEventsController.php';
require_once $c . 'ChecksController.php';
require_once $c . 'DashboardController.php';
require_once $c . 'EntitiesController.php';
require_once $c . 'LoansController.php';
require_once $c . 'ReportController.php';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$rawPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$path = is_string($rawPath) && $rawPath !== '' ? $rawPath : '/';
if ($path !== '/' && substr($path, -1) === '/') {
    $path = rtrim($path, '/') ?: '/';
}

$routeKey = $method . ' ' . $path;

$routes = [
    'GET /login' => static function (): void {
        (new AuthController())->loginForm();
    },
    'GET /login/cancel' => static function (): void {
        (new AuthController())->cancelPending();
    },
    'POST /login/request-otp' => static function (): void {
        (new AuthController())->requestOtp();
    },
    'POST /login/verify' => static function (): void {
        (new AuthController())->verifyOtp();
    },
    'POST /logout' => static function (): void {
        (new AuthController())->logout();
    },
    'GET /' => static function (): void {
        (new DashboardController())->index();
    },
    'GET /borrowers' => static function (): void {
        (new BorrowersController())->index();
    },
    'GET /borrowers/new' => static function (): void {
        (new BorrowersController())->newForm();
    },
    'POST /borrowers/new' => static function (): void {
        (new BorrowersController())->create();
    },
    'GET /borrowers/edit' => static function (): void {
        (new BorrowersController())->editForm();
    },
    'POST /borrowers/edit' => static function (): void {
        (new BorrowersController())->update();
    },
    'GET /entities' => static function (): void {
        (new EntitiesController())->index();
    },
    'GET /entities/new' => static function (): void {
        (new EntitiesController())->newForm();
    },
    'POST /entities/new' => static function (): void {
        (new EntitiesController())->create();
    },
    'GET /entities/edit' => static function (): void {
        (new EntitiesController())->editForm();
    },
    'POST /entities/edit' => static function (): void {
        (new EntitiesController())->update();
    },
    'GET /loans' => static function (): void {
        (new LoansController())->index();
    },
    'GET /checks' => static function (): void {
        (new ChecksController())->index();
    },
    'POST /checks' => static function (): void {
        (new ChecksController())->store();
    },
    'GET /cash-events' => static function (): void {
        (new CashEventsController())->index();
    },
    'GET /cash-events/new' => static function (): void {
        (new CashEventsController())->newForm();
    },
    'POST /cash-events/new' => static function (): void {
        (new CashEventsController())->create();
    },
    'GET /cash-events/edit' => static function (): void {
        (new CashEventsController())->editForm();
    },
    'POST /cash-events/edit' => static function (): void {
        (new CashEventsController())->update();
    },
    'POST /cash-events/delete' => static function (): void {
        (new CashEventsController())->destroy();
    },
    'GET /bank' => static function (): void {
        (new BankController())->showForm();
    },
    'POST /bank' => static function (): void {
        (new BankController())->store();
    },
    'GET /report' => static function (): void {
        (new ReportController())->index();
    },
    'GET /loans/new' => static function (): void {
        (new LoansController())->create();
    },
    'GET /loans/edit' => static function (): void {
        (new LoansController())->edit();
    },
    'POST /loans/new' => static function (): void {
        (new LoansController())->store();
    },
    'POST /loans/edit' => static function (): void {
        (new LoansController())->update();
    },
];
$publicAuthRoutes = ['GET /login', 'GET /login/cancel', 'POST /login/request-otp', 'POST /login/verify'];
if (!in_array($routeKey, $publicAuthRoutes, true)) {
    require_login();
}

$handler = $routes[$routeKey] ?? null;
if (!is_callable($handler)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    $message = 'Not Found';
    echo e($message) . "\n";
    exit;
}

try {
    $handler();
} catch (Throwable $e) {
    error_log('priv-lending ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    if ($showErrorDetail) {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: text/plain; charset=UTF-8');
        }
        echo $e->getMessage() . "\n" . $e->getFile() . ':' . $e->getLine() . "\n\n" . $e->getTraceAsString();
        exit;
    }
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
    }
    echo "Application error.\n";
    exit;
}
