<?php

declare(strict_types=1);

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'security_headers.php';
security_headers();

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'session.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'csrf.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'view.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'db.php';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$rawPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$path = is_string($rawPath) && $rawPath !== '' ? $rawPath : '/';
if ($path !== '/' && substr($path, -1) === '/') {
    $path = rtrim($path, '/') ?: '/';
}

$routeKey = $method . ' ' . $path;

$routes = [
    'GET /login' => static function (): void {
        $title = 'Login';
        header('Content-Type: text/html; charset=utf-8');
        echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><title>' . e($title) . '</title></head><body>';
        echo '<form method="post" action="/login">' . csrf_field() . '<button type="submit">Sign in</button></form>';
        echo '</body></html>';
    },
    'POST /login' => static function (): void {
        csrf_verify_or_die();
        login(1);
        header('Location: /');
        exit;
    },
    'POST /logout' => static function (): void {
        csrf_verify_or_die();
        logout();
        header('Location: /login');
        exit;
    },
    'GET /' => static function (): void {
        $title = 'Dashboard';
        $heading = 'Dashboard';
        header('Content-Type: text/html; charset=utf-8');
        echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><title>' . e($title) . '</title></head><body>';
        echo '<p>' . e($heading) . '</p>';
        echo '<p><a href="/borrowers">Borrowers</a> · <a href="/entities">Entities</a> · <a href="/loans">Loans</a></p>';
        echo '<form method="post" action="/logout">' . csrf_field() . '<button type="submit">Sign out</button></form>';
        echo '</body></html>';
    },
    'GET /borrowers' => static function (): void {
        $title = 'Borrowers';
        $rows = dbAll('SELECT id, name, notes FROM borrowers ORDER BY name ASC', []);
        header('Content-Type: text/html; charset=utf-8');
        echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">';
        echo '<script src="https://cdn.tailwindcss.com"></script>';
        echo '<title>' . e($title) . '</title></head><body class="min-h-screen bg-slate-50 p-6 text-slate-900">';
        echo '<div class="mx-auto max-w-3xl space-y-4">';
        echo '<div class="flex items-center justify-between gap-4">';
        echo '<h1 class="text-2xl font-semibold">' . e($title) . '</h1>';
        echo '<a class="rounded bg-slate-900 px-3 py-2 text-sm text-white" href="/borrowers/new">New borrower</a>';
        echo '</div>';
        echo '<a class="text-sm text-slate-600 underline" href="/">Dashboard</a>';
        echo '<div class="overflow-hidden rounded border border-slate-200 bg-white shadow-sm">';
        echo '<table class="min-w-full text-left text-sm"><thead class="bg-slate-100 text-slate-600"><tr>';
        echo '<th class="px-3 py-2 font-medium">ID</th><th class="px-3 py-2 font-medium">Name</th><th class="px-3 py-2 font-medium">Notes</th>';
        echo '</tr></thead><tbody>';
        if ($rows === []) {
            echo '<tr><td class="px-3 py-4 text-slate-500" colspan="3">No borrowers yet.</td></tr>';
        } else {
            foreach ($rows as $row) {
                $id = (string) ($row['id'] ?? '');
                $name = (string) ($row['name'] ?? '');
                $notes = $row['notes'] !== null && $row['notes'] !== '' ? (string) $row['notes'] : '';
                echo '<tr class="border-t border-slate-100">';
                echo '<td class="px-3 py-2">' . e($id) . '</td>';
                echo '<td class="px-3 py-2">' . e($name) . '</td>';
                echo '<td class="px-3 py-2">' . e($notes) . '</td>';
                echo '</tr>';
            }
        }
        echo '</tbody></table></div></div></body></html>';
    },
    'GET /borrowers/new' => static function (): void {
        $title = 'New borrower';
        header('Content-Type: text/html; charset=utf-8');
        echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">';
        echo '<script src="https://cdn.tailwindcss.com"></script>';
        echo '<title>' . e($title) . '</title></head><body class="min-h-screen bg-slate-50 p-6 text-slate-900">';
        echo '<div class="mx-auto max-w-md space-y-4">';
        echo '<h1 class="text-2xl font-semibold">' . e($title) . '</h1>';
        echo '<a class="text-sm text-slate-600 underline" href="/borrowers">Back to borrowers</a>';
        echo '<form class="space-y-4 rounded border border-slate-200 bg-white p-4 shadow-sm" method="post" action="/borrowers/new">';
        echo csrf_field();
        echo '<div><label class="mb-1 block text-sm font-medium text-slate-700" for="name">Name</label>';
        echo '<input class="w-full rounded border border-slate-300 px-3 py-2 text-sm" id="name" name="name" type="text" required maxlength="255"></div>';
        echo '<div><label class="mb-1 block text-sm font-medium text-slate-700" for="notes">Notes</label>';
        echo '<textarea class="w-full rounded border border-slate-300 px-3 py-2 text-sm" id="notes" name="notes" rows="4"></textarea></div>';
        echo '<div class="flex gap-2"><button class="rounded bg-slate-900 px-3 py-2 text-sm text-white" type="submit">Save</button>';
        echo '<a class="rounded border border-slate-300 px-3 py-2 text-sm text-slate-700" href="/borrowers">Cancel</a></div>';
        echo '</form></div></body></html>';
    },
    'POST /borrowers/new' => static function (): void {
        csrf_verify_or_die();
        $name = trim((string) ($_POST['name'] ?? ''));
        $notesRaw = trim((string) ($_POST['notes'] ?? ''));
        if ($name === '') {
            header('Location: /borrowers/new');
            exit;
        }
        $notes = $notesRaw === '' ? null : $notesRaw;
        $stmt = db()->prepare('INSERT INTO borrowers (name, notes) VALUES (?, ?)');
        $stmt->execute([$name, $notes]);
        header('Location: /borrowers');
        exit;
    },
    'GET /entities' => static function (): void {
        $title = 'Entities';
        $rows = dbAll(
            'SELECT e.id, e.name, e.borrower_id, b.name AS borrower_name FROM entities e INNER JOIN borrowers b ON b.id = e.borrower_id ORDER BY b.name ASC, e.name ASC',
            []
        );
        header('Content-Type: text/html; charset=utf-8');
        echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">';
        echo '<script src="https://cdn.tailwindcss.com"></script>';
        echo '<title>' . e($title) . '</title></head><body class="min-h-screen bg-slate-50 p-6 text-slate-900">';
        echo '<div class="mx-auto max-w-3xl space-y-4">';
        echo '<div class="flex items-center justify-between gap-4">';
        echo '<h1 class="text-2xl font-semibold">' . e($title) . '</h1>';
        echo '<a class="rounded bg-slate-900 px-3 py-2 text-sm text-white" href="/entities/new">New entity</a>';
        echo '</div>';
        echo '<a class="text-sm text-slate-600 underline" href="/">Dashboard</a>';
        echo '<div class="overflow-hidden rounded border border-slate-200 bg-white shadow-sm">';
        echo '<table class="min-w-full text-left text-sm"><thead class="bg-slate-100 text-slate-600"><tr>';
        echo '<th class="px-3 py-2 font-medium">ID</th><th class="px-3 py-2 font-medium">Borrower</th><th class="px-3 py-2 font-medium">Name</th>';
        echo '</tr></thead><tbody>';
        if ($rows === []) {
            echo '<tr><td class="px-3 py-4 text-slate-500" colspan="3">No entities yet.</td></tr>';
        } else {
            foreach ($rows as $row) {
                $id = (string) ($row['id'] ?? '');
                $borrowerName = (string) ($row['borrower_name'] ?? '');
                $entityName = (string) ($row['name'] ?? '');
                echo '<tr class="border-t border-slate-100">';
                echo '<td class="px-3 py-2">' . e($id) . '</td>';
                echo '<td class="px-3 py-2">' . e($borrowerName) . '</td>';
                echo '<td class="px-3 py-2">' . e($entityName) . '</td>';
                echo '</tr>';
            }
        }
        echo '</tbody></table></div></div></body></html>';
    },
    'GET /entities/new' => static function (): void {
        $title = 'New entity';
        $borrowers = dbAll('SELECT id, name FROM borrowers ORDER BY name ASC', []);
        header('Content-Type: text/html; charset=utf-8');
        echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">';
        echo '<script src="https://cdn.tailwindcss.com"></script>';
        echo '<title>' . e($title) . '</title></head><body class="min-h-screen bg-slate-50 p-6 text-slate-900">';
        echo '<div class="mx-auto max-w-md space-y-4">';
        echo '<h1 class="text-2xl font-semibold">' . e($title) . '</h1>';
        echo '<a class="text-sm text-slate-600 underline" href="/entities">Back to entities</a>';
        if ($borrowers === []) {
            echo '<p class="text-sm text-slate-600">No borrowers yet. <a class="underline" href="/borrowers/new">Create a borrower</a> first.</p>';
        } else {
            echo '<form class="space-y-4 rounded border border-slate-200 bg-white p-4 shadow-sm" method="post" action="/entities/new">';
            echo csrf_field();
            echo '<div><label class="mb-1 block text-sm font-medium text-slate-700" for="borrower_id">Borrower</label>';
            echo '<select class="w-full rounded border border-slate-300 px-3 py-2 text-sm" id="borrower_id" name="borrower_id" required>';
            foreach ($borrowers as $b) {
                $bid = (string) ($b['id'] ?? '');
                $bname = (string) ($b['name'] ?? '');
                echo '<option value="' . e($bid) . '">' . e($bname) . '</option>';
            }
            echo '</select></div>';
            echo '<div><label class="mb-1 block text-sm font-medium text-slate-700" for="name">Name</label>';
            echo '<input class="w-full rounded border border-slate-300 px-3 py-2 text-sm" id="name" name="name" type="text" required maxlength="255"></div>';
            echo '<div class="flex gap-2"><button class="rounded bg-slate-900 px-3 py-2 text-sm text-white" type="submit">Save</button>';
            echo '<a class="rounded border border-slate-300 px-3 py-2 text-sm text-slate-700" href="/entities">Cancel</a></div>';
            echo '</form>';
        }
        echo '</div></body></html>';
    },
    'POST /entities/new' => static function (): void {
        csrf_verify_or_die();
        $borrowerId = (int) ($_POST['borrower_id'] ?? 0);
        $name = trim((string) ($_POST['name'] ?? ''));
        if ($borrowerId < 1 || $name === '') {
            header('Location: /entities/new');
            exit;
        }
        $check = db()->prepare('SELECT id FROM borrowers WHERE id = ?');
        $check->execute([$borrowerId]);
        if ($check->fetch() === false) {
            header('Location: /entities/new');
            exit;
        }
        $stmt = db()->prepare('INSERT INTO entities (borrower_id, name) VALUES (?, ?)');
        $stmt->execute([$borrowerId, $name]);
        header('Location: /entities');
        exit;
    },
    'GET /loans' => static function (): void {
        $title = 'Loans';
        $rows = dbAll(
            'SELECT l.id, l.name, l.funding_source, l.origin_date, l.maturity_date, l.interest_type, l.monthly_interest, l.prepaid_interest_amount, l.prepaid_interest_date, e.name AS entity_name FROM loans l INNER JOIN entities e ON e.id = l.entity_id ORDER BY e.name ASC, l.name ASC',
            []
        );
        header('Content-Type: text/html; charset=utf-8');
        echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">';
        echo '<script src="https://cdn.tailwindcss.com"></script>';
        echo '<title>' . e($title) . '</title></head><body class="min-h-screen bg-slate-50 p-6 text-slate-900">';
        echo '<div class="mx-auto max-w-6xl space-y-4">';
        echo '<div class="flex items-center justify-between gap-4">';
        echo '<h1 class="text-2xl font-semibold">' . e($title) . '</h1>';
        echo '<a class="rounded bg-slate-900 px-3 py-2 text-sm text-white" href="/loans/new">New loan</a>';
        echo '</div>';
        echo '<a class="text-sm text-slate-600 underline" href="/">Dashboard</a>';
        echo '<div class="overflow-x-auto overflow-hidden rounded border border-slate-200 bg-white shadow-sm">';
        echo '<table class="min-w-full text-left text-sm"><thead class="bg-slate-100 text-slate-600"><tr>';
        echo '<th class="px-3 py-2 font-medium">ID</th><th class="px-3 py-2 font-medium">Entity</th><th class="px-3 py-2 font-medium">Name</th>';
        echo '<th class="px-3 py-2 font-medium">Funding</th><th class="px-3 py-2 font-medium">Origin</th><th class="px-3 py-2 font-medium">Maturity</th>';
        echo '<th class="px-3 py-2 font-medium">Interest</th><th class="px-3 py-2 font-medium">Monthly</th><th class="px-3 py-2 font-medium">Prepaid amt</th><th class="px-3 py-2 font-medium">Prepaid date</th>';
        echo '</tr></thead><tbody>';
        if ($rows === []) {
            echo '<tr><td class="px-3 py-4 text-slate-500" colspan="10">No loans yet.</td></tr>';
        } else {
            foreach ($rows as $row) {
                $id = (string) ($row['id'] ?? '');
                $entityName = (string) ($row['entity_name'] ?? '');
                $loanName = (string) ($row['name'] ?? '');
                $funding = (string) ($row['funding_source'] ?? '');
                $origin = (string) ($row['origin_date'] ?? '');
                $maturity = $row['maturity_date'] !== null && $row['maturity_date'] !== '' ? (string) $row['maturity_date'] : '';
                $itype = (string) ($row['interest_type'] ?? '');
                $monthly = $row['monthly_interest'] !== null && $row['monthly_interest'] !== '' ? (string) $row['monthly_interest'] : '';
                $pamt = $row['prepaid_interest_amount'] !== null && $row['prepaid_interest_amount'] !== '' ? (string) $row['prepaid_interest_amount'] : '';
                $pdate = $row['prepaid_interest_date'] !== null && $row['prepaid_interest_date'] !== '' ? (string) $row['prepaid_interest_date'] : '';
                echo '<tr class="border-t border-slate-100">';
                echo '<td class="px-3 py-2">' . e($id) . '</td>';
                echo '<td class="px-3 py-2">' . e($entityName) . '</td>';
                echo '<td class="px-3 py-2">' . e($loanName) . '</td>';
                echo '<td class="px-3 py-2">' . e($funding) . '</td>';
                echo '<td class="px-3 py-2">' . e($origin) . '</td>';
                echo '<td class="px-3 py-2">' . e($maturity) . '</td>';
                echo '<td class="px-3 py-2">' . e($itype) . '</td>';
                echo '<td class="px-3 py-2">' . e($monthly) . '</td>';
                echo '<td class="px-3 py-2">' . e($pamt) . '</td>';
                echo '<td class="px-3 py-2">' . e($pdate) . '</td>';
                echo '</tr>';
            }
        }
        echo '</tbody></table></div></div></body></html>';
    },
    'GET /loans/new' => static function (): void {
        $title = 'New loan';
        $entities = dbAll('SELECT id, name FROM entities ORDER BY name ASC', []);
        header('Content-Type: text/html; charset=utf-8');
        echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">';
        echo '<script src="https://cdn.tailwindcss.com"></script>';
        echo '<title>' . e($title) . '</title></head><body class="min-h-screen bg-slate-50 p-6 text-slate-900">';
        echo '<div class="mx-auto max-w-xl space-y-4">';
        echo '<h1 class="text-2xl font-semibold">' . e($title) . '</h1>';
        echo '<a class="text-sm text-slate-600 underline" href="/loans">Back to loans</a>';
        if ($entities === []) {
            echo '<p class="text-sm text-slate-600">No entities yet. <a class="underline" href="/entities/new">Create an entity</a> first.</p>';
        } else {
            echo '<form class="space-y-4 rounded border border-slate-200 bg-white p-4 shadow-sm" method="post" action="/loans/new">';
            echo csrf_field();
            echo '<div><label class="mb-1 block text-sm font-medium text-slate-700" for="entity_id">Entity</label>';
            echo '<select class="w-full rounded border border-slate-300 px-3 py-2 text-sm" id="entity_id" name="entity_id" required>';
            foreach ($entities as $ent) {
                $eid = (string) ($ent['id'] ?? '');
                $ename = (string) ($ent['name'] ?? '');
                echo '<option value="' . e($eid) . '">' . e($ename) . '</option>';
            }
            echo '</select></div>';
            echo '<div><label class="mb-1 block text-sm font-medium text-slate-700" for="name">Name</label>';
            echo '<input class="w-full rounded border border-slate-300 px-3 py-2 text-sm" id="name" name="name" type="text" required maxlength="255"></div>';
            echo '<div><label class="mb-1 block text-sm font-medium text-slate-700" for="funding_source">Funding source</label>';
            echo '<select class="w-full rounded border border-slate-300 px-3 py-2 text-sm" id="funding_source" name="funding_source" required>';
            echo '<option value="JPM">JPM</option><option value="NTRS">NTRS</option></select></div>';
            echo '<div><label class="mb-1 block text-sm font-medium text-slate-700" for="origin_date">Origin date</label>';
            echo '<input class="w-full rounded border border-slate-300 px-3 py-2 text-sm" id="origin_date" name="origin_date" type="date" required></div>';
            echo '<div><label class="mb-1 block text-sm font-medium text-slate-700" for="maturity_date">Maturity date (optional)</label>';
            echo '<input class="w-full rounded border border-slate-300 px-3 py-2 text-sm" id="maturity_date" name="maturity_date" type="date"></div>';
            echo '<fieldset class="space-y-2"><legend class="mb-1 text-sm font-medium text-slate-700">Interest type</legend>';
            echo '<label class="mr-4 text-sm"><input class="mr-1" type="radio" name="interest_type" value="monthly" required> Monthly</label>';
            echo '<label class="text-sm"><input class="mr-1" type="radio" name="interest_type" value="prepaid"> Prepaid</label></fieldset>';
            echo '<div><label class="mb-1 block text-sm font-medium text-slate-700" for="monthly_interest">Monthly interest (required if monthly)</label>';
            echo '<input class="w-full rounded border border-slate-300 px-3 py-2 text-sm" id="monthly_interest" name="monthly_interest" type="text" inputmode="decimal" placeholder="0.00"></div>';
            echo '<div><label class="mb-1 block text-sm font-medium text-slate-700" for="prepaid_interest_amount">Prepaid interest amount (required if prepaid)</label>';
            echo '<input class="w-full rounded border border-slate-300 px-3 py-2 text-sm" id="prepaid_interest_amount" name="prepaid_interest_amount" type="text" inputmode="decimal" placeholder="0.00"></div>';
            echo '<div><label class="mb-1 block text-sm font-medium text-slate-700" for="prepaid_interest_date">Prepaid interest date (required if prepaid)</label>';
            echo '<input class="w-full rounded border border-slate-300 px-3 py-2 text-sm" id="prepaid_interest_date" name="prepaid_interest_date" type="date"></div>';
            echo '<div class="flex gap-2"><button class="rounded bg-slate-900 px-3 py-2 text-sm text-white" type="submit">Save</button>';
            echo '<a class="rounded border border-slate-300 px-3 py-2 text-sm text-slate-700" href="/loans">Cancel</a></div>';
            echo '</form>';
        }
        echo '</div></body></html>';
    },
    'POST /loans/new' => static function (): void {
        csrf_verify_or_die();

        $parseDate = static function (string $s): ?string {
            $s = trim($s);
            if ($s === '') {
                return null;
            }
            $d = DateTimeImmutable::createFromFormat('Y-m-d', $s);

            return $d instanceof DateTimeImmutable && $d->format('Y-m-d') === $s ? $s : null;
        };

        $parseDecimal = static function (string $s): ?string {
            $s = trim($s);
            if ($s === '') {
                return null;
            }
            if (!preg_match('/^-?\d+(\.\d{1,2})?$/', $s)) {
                return null;
            }

            return $s;
        };

        $entityId = (int) ($_POST['entity_id'] ?? 0);
        $name = trim((string) ($_POST['name'] ?? ''));
        $funding = (string) ($_POST['funding_source'] ?? '');
        $originRaw = trim((string) ($_POST['origin_date'] ?? ''));
        $maturityRaw = trim((string) ($_POST['maturity_date'] ?? ''));
        $interestType = (string) ($_POST['interest_type'] ?? '');
        $monthlyRaw = trim((string) ($_POST['monthly_interest'] ?? ''));
        $prepaidAmtRaw = trim((string) ($_POST['prepaid_interest_amount'] ?? ''));
        $prepaidDateRaw = trim((string) ($_POST['prepaid_interest_date'] ?? ''));

        $redirect = static function (): void {
            header('Location: /loans/new');
            exit;
        };

        if ($entityId < 1 || $name === '' || !in_array($funding, ['JPM', 'NTRS'], true) || !in_array($interestType, ['monthly', 'prepaid'], true)) {
            $redirect();
        }

        $origin = $parseDate($originRaw);
        if ($origin === null) {
            $redirect();
        }

        $maturity = $maturityRaw === '' ? null : $parseDate($maturityRaw);
        if ($maturityRaw !== '' && $maturity === null) {
            $redirect();
        }

        $monthlyInterest = null;
        $prepaidAmount = null;
        $prepaidDate = null;

        if ($interestType === 'monthly') {
            $monthlyInterest = $parseDecimal($monthlyRaw);
            if ($monthlyInterest === null) {
                $redirect();
            }
        } else {
            $prepaidAmount = $parseDecimal($prepaidAmtRaw);
            $prepaidDate = $parseDate($prepaidDateRaw);
            if ($prepaidAmount === null || $prepaidDate === null) {
                $redirect();
            }
        }

        $chk = db()->prepare('SELECT id FROM entities WHERE id = ?');
        $chk->execute([$entityId]);
        if ($chk->fetch() === false) {
            $redirect();
        }

        $stmt = db()->prepare(
            'INSERT INTO loans (entity_id, name, funding_source, origin_date, maturity_date, interest_type, monthly_interest, prepaid_interest_amount, prepaid_interest_date, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NULL)'
        );
        $stmt->execute([
            $entityId,
            $name,
            $funding,
            $origin,
            $maturity,
            $interestType,
            $monthlyInterest,
            $prepaidAmount,
            $prepaidDate,
        ]);
        header('Location: /loans');
        exit;
    },
];

if ($routeKey !== 'GET /login' && $routeKey !== 'POST /login') {
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

$handler();
