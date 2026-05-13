<?php

declare(strict_types=1);

final class LoansController
{
    public function index(): void
    {
        $title = 'Loans';
        $idx = loan_loans_column_name_index();
        $monthlySel = isset($idx['monthly_interest'])
            ? 'l.monthly_interest'
            : 'CAST(NULL AS DECIMAL(12,2)) AS monthly_interest';
        $icmSel = isset($idx['interest_calc_method'])
            ? 'l.interest_calc_method'
            : "'fixed' AS interest_calc_method";
        $rows = dbAll(
            'SELECT l.id, l.name, l.funding_source, l.origin_date, l.maturity_date, l.payment_type, l.principal_amount, l.annual_interest_rate, '
            . $monthlySel . ', ' . $icmSel
            . ', l.prepaid_interest_amount, l.prepaid_interest_date, e.name AS entity_name FROM loans l INNER JOIN entities e ON e.id = l.entity_id ORDER BY e.name ASC, l.name ASC',
            []
        );

        $loanRows = $this->buildLoanListDisplayRows($rows);

        header('Content-Type: text/html; charset=utf-8');
        render('loans', [
            'title' => $title,
            'loanRows' => $loanRows,
            'rowsEmpty' => $rows === [],
        ]);
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return list<array<string, string|bool>>
     */
    private function buildLoanListDisplayRows(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            $id = (string) ($row['id'] ?? '');
            $entityName = (string) ($row['entity_name'] ?? '');
            $loanName = (string) ($row['name'] ?? '');
            $funding = (string) ($row['funding_source'] ?? '');
            $origin = (string) ($row['origin_date'] ?? '');
            $maturity = $row['maturity_date'] !== null && $row['maturity_date'] !== '' ? (string) $row['maturity_date'] : '';
            $ptype = (string) ($row['payment_type'] ?? '');
            $principal = $row['principal_amount'] !== null && $row['principal_amount'] !== '' ? (string) $row['principal_amount'] : '';
            $rate = $row['annual_interest_rate'] !== null && $row['annual_interest_rate'] !== '' ? (string) $row['annual_interest_rate'] : '';
            $monthlyIntStr = isset($row['monthly_interest']) && $row['monthly_interest'] !== null && $row['monthly_interest'] !== '' ? (string) $row['monthly_interest'] : '';
            $calcMethod = (string) ($row['interest_calc_method'] ?? 'fixed');
            if (!in_array($calcMethod, ['fixed', 'declining_balance'], true)) {
                $calcMethod = 'fixed';
            }
            $impliedAnnual = null;
            if ($calcMethod === 'fixed'
                && in_array($ptype, ['interest_only', 'amortizing'], true)
                && loan_annual_interest_rate_is_blank_or_zero($rate)
                && $monthlyIntStr !== '') {
                $impliedAnnual = loan_implied_annual_percent_from_monthly_interest($principal, $monthlyIntStr);
            }
            if (in_array($ptype, ['interest_only', 'amortizing'], true)) {
                if ($impliedAnnual !== null) {
                    $estMonthly = checks_normalize_money_2($monthlyIntStr);
                } else {
                    $estMonthly = loan_simple_monthly_interest($principal, $rate);
                }
            } else {
                $estMonthly = loan_simple_monthly_interest($principal, $rate);
            }
            $principalTitle = in_array($ptype, ['interest_only', 'amortizing'], true)
                ? 'Est. monthly interest (full principal, not amortized): ' . $estMonthly
                : '';

            $out[] = [
                'id' => $id,
                'entityName' => $entityName,
                'loanName' => $loanName,
                'funding' => $funding,
                'origin' => $origin,
                'maturity' => $maturity,
                'principal' => $principal,
                'principalTitle' => $principalTitle,
                'rateIsImplied' => $impliedAnnual !== null,
                'impliedAnnual' => $impliedAnnual !== null ? (string) $impliedAnnual : '',
                'rateDisplay' => $rate !== '' ? $rate : '0',
                'ptype' => $ptype,
            ];
        }

        return $out;
    }

    public function create(): void
    {
        $title = 'New loan';
        $entities = dbAll('SELECT id, name FROM entities ORDER BY name ASC', []);
        $showInvalid = isset($_GET['invalid']);
        $entitiesEmpty = $entities === [];

        header('Content-Type: text/html; charset=utf-8');
        render('loans_new', [
            'title' => $title,
            'entities' => $entities,
            'showInvalid' => $showInvalid,
            'entitiesEmpty' => $entitiesEmpty,
            'showFundingPrincipalOutPostedColumn' => schema_table_has_column('loans', 'funding_principal_out_posted'),
        ]);
    }

    public function store(): void
    {
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
        $paymentType = trim((string) ($_POST['payment_type'] ?? ''));
        $principalRaw = loan_normalize_decimal_input((string) ($_POST['principal_amount'] ?? ''));
        $rateRaw = loan_normalize_decimal_input((string) ($_POST['annual_interest_rate'] ?? ''), true);
        $prepaidAmtRaw = loan_normalize_decimal_input((string) ($_POST['prepaid_interest_amount'] ?? ''));
        $prepaidDateRaw = trim((string) ($_POST['prepaid_interest_date'] ?? ''));

        $redirect = static function (): void {
            header('Location: /loans/new?invalid=1');
            exit;
        };

        if ($entityId < 1 || $name === '' || !in_array($funding, ['JPM', 'NTRS'], true) || !in_array($paymentType, ['interest_only', 'prepaid', 'amortizing'], true)) {
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

        $checksFields = loan_parse_checks_fields_from_post();
        if ($checksFields === false) {
            $redirect();
        }

        $principalStr = '0.00';
        $rateStr = '0.00';
        $prepaidAmount = null;
        $prepaidDate = null;

        if ($paymentType === 'prepaid') {
            $prepaidAmount = $parseDecimal($prepaidAmtRaw);
            $prepaidDate = $parseDate($prepaidDateRaw);
            if ($prepaidAmount === null || $prepaidDate === null) {
                $redirect();
            }
            if (extension_loaded('bcmath')) {
                if (bccomp($prepaidAmount, '0', 2) !== 1) {
                    $redirect();
                }
            } elseif ((float) $prepaidAmount <= 0.0) {
                $redirect();
            }
            $parsed = loan_principal_and_annual_for_prepaid_save($principalRaw, $rateRaw, $checksFields);
            if ($parsed === false) {
                $redirect();
            }
            $principalStr = $parsed['principalStr'];
            $rateStr = $parsed['rateStr'];
        } else {
            $parsed = loan_principal_and_annual_for_io_amortizing_save($principalRaw, $rateRaw, $checksFields);
            if ($parsed === false) {
                $redirect();
            }
            $principalStr = $parsed['principalStr'];
            $rateStr = $parsed['rateStr'];
        }

        $chk = db()->prepare('SELECT id FROM entities WHERE id = ?');
        $chk->execute([$entityId]);
        if ($chk->fetch() === false) {
            $redirect();
        }

        $createFunding = isset($_POST['create_funding_principal_out']);
        if ($createFunding && !schema_table_has_column('loans', 'funding_principal_out_posted')) {
            $redirect();
        }

        $idx = loan_loans_column_name_index();
        $insertCols = ['entity_id', 'name', 'principal_amount', 'annual_interest_rate'];
        $insertParams = [$entityId, $name, $principalStr, $rateStr];
        if (isset($idx['monthly_interest'])) {
            $insertCols[] = 'monthly_interest';
            $insertParams[] = $checksFields['monthly_interest'];
        }
        if (isset($idx['interest_calc_method'])) {
            $insertCols[] = 'interest_calc_method';
            $insertParams[] = $checksFields['interest_calc_method'];
        }
        if (isset($idx['principal_payment_monthly'])) {
            $insertCols[] = 'principal_payment_monthly';
            $insertParams[] = $checksFields['principal_payment_monthly'];
        }
        $insertCols = array_merge($insertCols, ['funding_source', 'origin_date', 'maturity_date', 'payment_type', 'prepaid_interest_amount', 'prepaid_interest_date', 'notes']);
        $insertParams = array_merge($insertParams, [$funding, $origin, $maturity, $paymentType, $prepaidAmount, $prepaidDate, null]);
        $ph = implode(', ', array_fill(0, count($insertParams), '?'));
        $sqlIns = 'INSERT INTO loans (' . implode(', ', $insertCols) . ') VALUES (' . $ph . ')';

        if ($createFunding) {
            if (extension_loaded('bcmath')) {
                if (bccomp($principalStr, '0', 2) !== 1) {
                    $redirect();
                }
            } elseif ((float) $principalStr <= 0.0) {
                $redirect();
            }
        }

        $pdo = db();
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare($sqlIns);
            $stmt->execute($insertParams);
            $newId = (int) $pdo->lastInsertId();
            if ($newId < 1) {
                throw new RuntimeException('Loan insert failed');
            }
            if ($createFunding) {
                loan_insert_funding_principal_out_cash_event($newId, $principalStr, $origin, $funding, $name);
                $mark = $pdo->prepare('UPDATE loans SET funding_principal_out_posted = 1 WHERE id = ?');
                $mark->execute([$newId]);
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        header('Location: /loans');
        exit;
    }

    public function edit(): void
    {
        $id = (int) ($_GET['id'] ?? 0);
        if ($id < 1) {
            header('Location: /loans');
            exit;
        }
        $fpSel = schema_table_has_column('loans', 'funding_principal_out_posted')
            ? 'funding_principal_out_posted'
            : 'CAST(0 AS UNSIGNED) AS funding_principal_out_posted';
        $loan = dbOne(
            'SELECT id, entity_id, name, funding_source, origin_date, maturity_date, payment_type, principal_amount, annual_interest_rate, '
            . loan_sql_select_checks_column_expressions('')
            . ', prepaid_interest_amount, prepaid_interest_date, ' . $fpSel . ' FROM loans WHERE id = ?',
            [$id]
        );
        if ($loan === null) {
            header('Location: /loans');
            exit;
        }
        $title = 'Edit loan';
        $entities = dbAll('SELECT id, name FROM entities ORDER BY name ASC', []);
        $lid = (string) ($loan['id'] ?? '');
        $curEntityId = (string) ($loan['entity_id'] ?? '');
        $nameVal = (string) ($loan['name'] ?? '');
        $funding = (string) ($loan['funding_source'] ?? '');
        $origin = (string) ($loan['origin_date'] ?? '');
        $maturity = $loan['maturity_date'] !== null && $loan['maturity_date'] !== '' ? (string) $loan['maturity_date'] : '';
        $ptype = (string) ($loan['payment_type'] ?? '');
        $principalVal = $loan['principal_amount'] !== null && $loan['principal_amount'] !== '' ? (string) $loan['principal_amount'] : '';
        $rateVal = $loan['annual_interest_rate'] !== null && $loan['annual_interest_rate'] !== '' ? (string) $loan['annual_interest_rate'] : '';
        $pamtVal = $loan['prepaid_interest_amount'] !== null && $loan['prepaid_interest_amount'] !== '' ? (string) $loan['prepaid_interest_amount'] : '';
        $pdateVal = $loan['prepaid_interest_date'] !== null && $loan['prepaid_interest_date'] !== '' ? (string) $loan['prepaid_interest_date'] : '';
        $icm = (string) ($loan['interest_calc_method'] ?? 'fixed');
        if (!in_array($icm, ['fixed', 'declining_balance'], true)) {
            $icm = 'fixed';
        }
        $chkIcFixed = $icm === 'fixed' ? ' checked' : '';
        $chkIcDecl = $icm === 'declining_balance' ? ' checked' : '';
        $mIntVal = $loan['monthly_interest'] !== null && $loan['monthly_interest'] !== '' ? (string) $loan['monthly_interest'] : '';
        $mppVal = $loan['principal_payment_monthly'] !== null && $loan['principal_payment_monthly'] !== '' ? (string) $loan['principal_payment_monthly'] : '';
        $selJpm = $funding === 'JPM' ? ' selected' : '';
        $selNtrs = $funding === 'NTRS' ? ' selected' : '';
        $chkIo = $ptype === 'interest_only' ? ' checked' : '';
        $chkAm = $ptype === 'amortizing' ? ' checked' : '';
        $chkPre = $ptype === 'prepaid' ? ' checked' : '';
        $hasFundingPostedCol = schema_table_has_column('loans', 'funding_principal_out_posted');
        $fundingPosted = $hasFundingPostedCol && (int) ($loan['funding_principal_out_posted'] ?? 0) === 1;
        echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">';
        echo '<script src="https://cdn.tailwindcss.com"></script>';
        echo '<title>' . e($title) . '</title></head><body class="min-h-screen bg-slate-50 p-6 text-slate-900">';
        echo '<div class="mx-auto max-w-xl space-y-4">';
        echo '<h1 class="text-2xl font-semibold">' . e($title) . '</h1>';
        echo '<a class="text-sm text-slate-600 underline" href="/loans">Back to loans</a>';
        if (isset($_GET['invalid'])) {
            echo '<p class="rounded border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-900">The loan was not saved. For interest-only or amortizing: principal must be greater than zero. Annual rate must be greater than zero unless you use <strong>fixed</strong> with <strong>monthly interest</strong> filled in (then annual rate may be blank or zero). Check number formats (use 100000.00 or 100000,00). Optional monthly amounts must be non-negative with at most two decimal places. Posting a funding transaction requires positive principal and migration <code class="text-xs">0008_loans_funding_principal_out_posted.sql</code>.</p>';
        }
        if ($entities === []) {
            echo '<p class="text-sm text-slate-600">No entities yet. <a class="underline" href="/entities/new">Create an entity</a> first.</p>';
        } else {
            echo '<form class="space-y-4 rounded border border-slate-200 bg-white p-4 shadow-sm" method="post" action="/loans/edit">';
            echo csrf_field();
            echo '<input type="hidden" name="id" value="' . e($lid) . '">';
            echo '<div><label class="mb-1 block text-sm font-medium text-slate-700" for="entity_id">Entity</label>';
            echo '<select class="w-full rounded border border-slate-300 px-3 py-2 text-sm" id="entity_id" name="entity_id" required>';
            foreach ($entities as $ent) {
                $eid = (string) ($ent['id'] ?? '');
                $ename = (string) ($ent['name'] ?? '');
                $sel = $eid === $curEntityId ? ' selected' : '';
                echo '<option value="' . e($eid) . '"' . $sel . '>' . e($ename) . '</option>';
            }
            echo '</select></div>';
            echo '<div><label class="mb-1 block text-sm font-medium text-slate-700" for="name">Name</label>';
            echo '<input class="w-full rounded border border-slate-300 px-3 py-2 text-sm" id="name" name="name" type="text" required maxlength="255" value="' . e($nameVal) . '"></div>';
            echo '<div><label class="mb-1 block text-sm font-medium text-slate-700" for="funding_source">Funding source</label>';
            echo '<select class="w-full rounded border border-slate-300 px-3 py-2 text-sm" id="funding_source" name="funding_source" required>';
            echo '<option value="JPM"' . $selJpm . '>JPM</option><option value="NTRS"' . $selNtrs . '>NTRS</option></select></div>';
            echo '<div><label class="mb-1 block text-sm font-medium text-slate-700" for="origin_date">Origin date</label>';
            echo '<input class="w-full rounded border border-slate-300 px-3 py-2 text-sm" id="origin_date" name="origin_date" type="date" required value="' . e($origin) . '"></div>';
            echo '<div><label class="mb-1 block text-sm font-medium text-slate-700" for="maturity_date">Maturity date (optional)</label>';
            echo '<input class="w-full rounded border border-slate-300 px-3 py-2 text-sm" id="maturity_date" name="maturity_date" type="date" value="' . e($maturity) . '"></div>';
            echo '<fieldset class="space-y-2"><legend class="mb-1 text-sm font-medium text-slate-700">Payment type</legend>';
            echo '<label class="mr-4 block text-sm"><input class="mr-1" type="radio" name="payment_type" value="interest_only" required' . $chkIo . '> Interest only</label>';
            echo '<label class="mr-4 block text-sm"><input class="mr-1" type="radio" name="payment_type" value="amortizing"' . $chkAm . '> Amortizing</label>';
            echo '<label class="block text-sm"><input class="mr-1" type="radio" name="payment_type" value="prepaid"' . $chkPre . '> Prepaid</label></fieldset>';
            echo '<div><label class="mb-1 block text-sm font-medium text-slate-700" for="principal_amount">Principal amount</label>';
            echo '<input class="w-full rounded border border-slate-300 px-3 py-2 text-sm" id="principal_amount" name="principal_amount" type="text" inputmode="decimal" placeholder="0.00" value="' . e($principalVal) . '"></div>';
            echo '<div><label class="mb-1 block text-sm font-medium text-slate-700" for="annual_interest_rate">Annual interest rate (%)</label>';
            echo '<input class="w-full rounded border border-slate-300 px-3 py-2 text-sm" id="annual_interest_rate" name="annual_interest_rate" type="text" inputmode="decimal" placeholder="e.g. 12.500" value="' . e($rateVal) . '"></div>';
            echo '<fieldset class="space-y-3 rounded border border-slate-200 p-3"><legend class="text-sm font-medium text-slate-700">Checks &amp; amortization</legend>';
            echo '<p class="text-xs text-slate-500">For <strong>interest-only</strong>, <strong>amortizing</strong>, and <strong>prepaid</strong> (post-prepaid Checks). Declining balance uses monthly principal below on the Checks page.</p>';
            echo '<div><span class="mb-1 block text-sm font-medium text-slate-700">Interest calculation method</span>';
            echo '<label class="mr-4 block text-sm"><input class="mr-1" type="radio" name="interest_calc_method" value="fixed" required' . $chkIcFixed . '> Fixed</label>';
            echo '<label class="block text-sm"><input class="mr-1" type="radio" name="interest_calc_method" value="declining_balance"' . $chkIcDecl . '> Declining balance</label></div>';
            echo '<div><label class="mb-1 block text-sm font-medium text-slate-700" for="monthly_interest">Monthly interest (optional)</label>';
            echo '<input class="w-full rounded border border-slate-300 px-3 py-2 text-sm" id="monthly_interest" name="monthly_interest" type="text" inputmode="decimal" placeholder="Leave blank to derive from principal and rate on Checks" value="' . e($mIntVal) . '"></div>';
            echo '<div><label class="mb-1 block text-sm font-medium text-slate-700" for="principal_payment_monthly">Monthly principal payment (paydown)</label>';
            echo '<input class="w-full rounded border border-slate-300 px-3 py-2 text-sm" id="principal_payment_monthly" name="principal_payment_monthly" type="text" inputmode="decimal" placeholder="0.00 — typical for amortizing" value="' . e($mppVal) . '"></div>';
            echo '</fieldset>';
            echo '<p class="text-xs text-slate-500">Interest only and amortizing: principal required; annual rate required unless <strong>fixed</strong> with <strong>monthly interest</strong> set. Prepaid: prepaid amount and date required; principal may be zero during prepaid, but rate, monthly interest, method, and paydown are saved for Checks after prepaid expires. Optional amounts: non-negative, up to two decimal places.</p>';
            echo '<div><label class="mb-1 block text-sm font-medium text-slate-700" for="prepaid_interest_amount">Prepaid interest amount</label>';
            echo '<input class="w-full rounded border border-slate-300 px-3 py-2 text-sm" id="prepaid_interest_amount" name="prepaid_interest_amount" type="text" inputmode="decimal" placeholder="0.00" value="' . e($pamtVal) . '"></div>';
            echo '<div><label class="mb-1 block text-sm font-medium text-slate-700" for="prepaid_interest_date">Prepaid interest date</label>';
            echo '<input class="w-full rounded border border-slate-300 px-3 py-2 text-sm" id="prepaid_interest_date" name="prepaid_interest_date" type="date" value="' . e($pdateVal) . '"></div>';
            if (!$hasFundingPostedCol) {
                echo '<p class="rounded border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-900">Funding transaction tracking requires migration <code class="text-xs">0008_loans_funding_principal_out_posted.sql</code>. Run <code class="text-xs">php bin/migrate.php</code>.</p>';
            } elseif ($fundingPosted) {
                echo '<p class="rounded border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm font-medium text-emerald-900">Funding transaction (principal_out): Posted</p>';
            } else {
                echo '<label class="flex items-start gap-2 text-sm text-slate-800"><input class="mt-1 h-4 w-4 rounded border-slate-300" type="checkbox" name="post_funding_principal_out" value="1"> <span><span class="font-medium">Post funding transaction (principal_out) now</span><span class="block text-xs font-normal text-slate-500">Uses current principal, origin date, and funding source; amount is stored negative.</span></span></label>';
            }
            echo '<div class="flex gap-2"><button class="rounded bg-slate-900 px-3 py-2 text-sm text-white" type="submit">Save</button>';
            echo '<a class="rounded border border-slate-300 px-3 py-2 text-sm text-slate-700" href="/loans">Cancel</a></div>';
            echo '</form>';
        }
        echo '</div></body></html>';
    }

    public function update(): void
    {
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

        $loanId = (int) ($_POST['id'] ?? 0);
        if ($loanId < 1) {
            header('Location: /loans');
            exit;
        }

        $fundingPostedFlag = false;
        if (schema_table_has_column('loans', 'funding_principal_out_posted')) {
            $fpr = dbOne('SELECT funding_principal_out_posted AS v FROM loans WHERE id = ?', [$loanId]);
            $fundingPostedFlag = $fpr !== null && (int) ($fpr['v'] ?? 0) === 1;
        }

        $redirect = static function (int $lid): void {
            header('Location: /loans/edit?id=' . $lid . '&invalid=1');
            exit;
        };

        $entityId = (int) ($_POST['entity_id'] ?? 0);
        $name = trim((string) ($_POST['name'] ?? ''));
        $funding = (string) ($_POST['funding_source'] ?? '');
        $originRaw = trim((string) ($_POST['origin_date'] ?? ''));
        $maturityRaw = trim((string) ($_POST['maturity_date'] ?? ''));
        $paymentType = trim((string) ($_POST['payment_type'] ?? ''));
        $principalRaw = loan_normalize_decimal_input((string) ($_POST['principal_amount'] ?? ''));
        $rateRaw = loan_normalize_decimal_input((string) ($_POST['annual_interest_rate'] ?? ''), true);
        $prepaidAmtRaw = loan_normalize_decimal_input((string) ($_POST['prepaid_interest_amount'] ?? ''));
        $prepaidDateRaw = trim((string) ($_POST['prepaid_interest_date'] ?? ''));

        if ($entityId < 1 || $name === '' || !in_array($funding, ['JPM', 'NTRS'], true) || !in_array($paymentType, ['interest_only', 'prepaid', 'amortizing'], true)) {
            $redirect($loanId);
        }

        $origin = $parseDate($originRaw);
        if ($origin === null) {
            $redirect($loanId);
        }

        $maturity = $maturityRaw === '' ? null : $parseDate($maturityRaw);
        if ($maturityRaw !== '' && $maturity === null) {
            $redirect($loanId);
        }

        $checksFields = loan_parse_checks_fields_from_post();
        if ($checksFields === false) {
            $redirect($loanId);
        }

        $principalStr = '0.00';
        $rateStr = '0.00';
        $prepaidAmount = null;
        $prepaidDate = null;

        if ($paymentType === 'prepaid') {
            $prepaidAmount = $parseDecimal($prepaidAmtRaw);
            $prepaidDate = $parseDate($prepaidDateRaw);
            if ($prepaidAmount === null || $prepaidDate === null) {
                $redirect($loanId);
            }
            if (extension_loaded('bcmath')) {
                if (bccomp($prepaidAmount, '0', 2) !== 1) {
                    $redirect($loanId);
                }
            } elseif ((float) $prepaidAmount <= 0.0) {
                $redirect($loanId);
            }
            $parsed = loan_principal_and_annual_for_prepaid_save($principalRaw, $rateRaw, $checksFields);
            if ($parsed === false) {
                $redirect($loanId);
            }
            $principalStr = $parsed['principalStr'];
            $rateStr = $parsed['rateStr'];
        } else {
            $parsed = loan_principal_and_annual_for_io_amortizing_save($principalRaw, $rateRaw, $checksFields);
            if ($parsed === false) {
                $redirect($loanId);
            }
            $principalStr = $parsed['principalStr'];
            $rateStr = $parsed['rateStr'];
        }

        $postFunding = isset($_POST['post_funding_principal_out']);
        if ($postFunding && !schema_table_has_column('loans', 'funding_principal_out_posted')) {
            $redirect($loanId);
        }
        if ($postFunding && $fundingPostedFlag) {
            $postFunding = false;
        }
        if ($postFunding) {
            if (extension_loaded('bcmath')) {
                if (bccomp($principalStr, '0', 2) !== 1) {
                    $redirect($loanId);
                }
            } elseif ((float) $principalStr <= 0.0) {
                $redirect($loanId);
            }
        }

        $chk = db()->prepare('SELECT id FROM entities WHERE id = ?');
        $chk->execute([$entityId]);
        if ($chk->fetch() === false) {
            $redirect($loanId);
        }

        $exists = db()->prepare('SELECT id FROM loans WHERE id = ?');
        $exists->execute([$loanId]);
        if ($exists->fetch() === false) {
            header('Location: /loans');
            exit;
        }

        $idx = loan_loans_column_name_index();
        $setParts = ['entity_id = ?', 'name = ?', 'principal_amount = ?', 'annual_interest_rate = ?'];
        $updParams = [$entityId, $name, $principalStr, $rateStr];
        if (isset($idx['monthly_interest'])) {
            $setParts[] = 'monthly_interest = ?';
            $updParams[] = $checksFields['monthly_interest'];
        }
        if (isset($idx['interest_calc_method'])) {
            $setParts[] = 'interest_calc_method = ?';
            $updParams[] = $checksFields['interest_calc_method'];
        }
        if (isset($idx['principal_payment_monthly'])) {
            $setParts[] = 'principal_payment_monthly = ?';
            $updParams[] = $checksFields['principal_payment_monthly'];
        }
        $setParts = array_merge($setParts, ['funding_source = ?', 'origin_date = ?', 'maturity_date = ?', 'payment_type = ?', 'prepaid_interest_amount = ?', 'prepaid_interest_date = ?']);
        $updParams = array_merge($updParams, [$funding, $origin, $maturity, $paymentType, $prepaidAmount, $prepaidDate, $loanId]);
        $sqlUpd = 'UPDATE loans SET ' . implode(', ', $setParts) . ' WHERE id = ?';
        $pdo = db();
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare($sqlUpd);
            $stmt->execute($updParams);
            if ($postFunding) {
                loan_insert_funding_principal_out_cash_event($loanId, $principalStr, $origin, $funding, $name);
                $mark = $pdo->prepare('UPDATE loans SET funding_principal_out_posted = 1 WHERE id = ? AND funding_principal_out_posted = 0');
                $mark->execute([$loanId]);
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        header('Location: /loans');
        exit;
    }
}
