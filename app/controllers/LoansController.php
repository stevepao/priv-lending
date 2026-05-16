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
        $balSub = loan_sql_cash_principal_balance_subquery();
        $closedSel = isset($idx['closed_date']) ? 'l.closed_date' : 'CAST(NULL AS DATE) AS closed_date';
        $rows = dbAll(
            'SELECT l.id, l.name, l.funding_source, l.origin_date, l.payment_type, l.principal_amount, l.annual_interest_rate, '
            . $monthlySel . ', ' . $icmSel
            . ', l.prepaid_interest_amount, l.prepaid_interest_date, ' . $closedSel . ', e.name AS entity_name, '
            . $balSub . ' AS current_balance_raw FROM loans l INNER JOIN entities e ON e.id = l.entity_id '
            . 'ORDER BY l.origin_date ASC, l.id ASC',
            []
        );

        $openLoanRows = [];
        $closedLoanRows = [];
        foreach ($rows as $row) {
            $display = $this->buildLoanListDisplayRow($row);
            $cd = $row['closed_date'] ?? null;
            if ($cd !== null && (string) $cd !== '') {
                $closedLoanRows[] = $display;
            } else {
                $openLoanRows[] = $display;
            }
        }

        header('Content-Type: text/html; charset=utf-8');
        render('loans', [
            'title' => $title,
            'openLoanRows' => $openLoanRows,
            'closedLoanRows' => $closedLoanRows,
        ]);
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, string|bool>
     */
    private function buildLoanListDisplayRow(array $row): array
    {
        $id = (string) ($row['id'] ?? '');
        $entityName = (string) ($row['entity_name'] ?? '');
        $loanName = (string) ($row['name'] ?? '');
        $funding = (string) ($row['funding_source'] ?? '');
        $origin = (string) ($row['origin_date'] ?? '');
        $ptype = (string) ($row['payment_type'] ?? '');
        $balRaw = $row['current_balance_raw'] ?? null;
        $balStr = $balRaw !== null && $balRaw !== '' ? (string) $balRaw : '0';
        $currentBalance = checks_normalize_money_2($balStr);
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

        return [
            'id' => $id,
            'entityName' => $entityName,
            'loanName' => $loanName,
            'funding' => $funding,
            'origin' => $origin,
            'currentBalance' => $currentBalance,
            'principal' => $principal,
            'principalTitle' => $principalTitle,
            'rateIsImplied' => $impliedAnnual !== null,
            'impliedAnnual' => $impliedAnnual !== null ? (string) $impliedAnnual : '',
            'rateDisplay' => $rate !== '' ? $rate : '0',
            'ptype' => $ptype,
        ];
    }

    private static function loanFormParseDateYmd(string $s): ?string
    {
        $s = trim($s);
        if ($s === '') {
            return null;
        }
        $d = DateTimeImmutable::createFromFormat('Y-m-d', $s);

        return $d instanceof DateTimeImmutable && $d->format('Y-m-d') === $s ? $s : null;
    }

    private static function loanFormParseDecimalTwoPlaces(string $s): ?string
    {
        $s = trim($s);
        if ($s === '') {
            return null;
        }
        if (!preg_match('/^-?\d+(\.\d{1,2})?$/', $s)) {
            return null;
        }

        return $s;
    }

    /**
     * Shared POST parsing/validation for loan create and update (invalid → caller redirects).
     *
     * @return array{
     *     entityId: int,
     *     name: string,
     *     funding: string,
     *     origin: string,
     *     maturity: string|null,
     *     paymentType: string,
     *     principalStr: string,
     *     rateStr: string,
     *     prepaidAmount: string|null,
     *     prepaidDate: string|null,
     *     checksFields: array{monthly_interest: ?string, interest_calc_method: string, principal_payment_monthly: ?string}
     * }|null
     */
    private function parseLoanFormSavePayload(): ?array
    {
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

        if ($entityId < 1 || $name === '' || !lending_funding_source_is_valid($funding) || !in_array($paymentType, ['interest_only', 'prepaid', 'amortizing'], true)) {
            return null;
        }

        $origin = self::loanFormParseDateYmd($originRaw);
        if ($origin === null) {
            return null;
        }

        $maturity = $maturityRaw === '' ? null : self::loanFormParseDateYmd($maturityRaw);
        if ($maturityRaw !== '' && $maturity === null) {
            return null;
        }

        $checksFields = loan_parse_checks_fields_from_post();
        if ($checksFields === false) {
            return null;
        }

        $principalStr = '0.00';
        $rateStr = '0.00';
        $prepaidAmount = null;
        $prepaidDate = null;

        if ($paymentType === 'prepaid') {
            $prepaidAmount = self::loanFormParseDecimalTwoPlaces($prepaidAmtRaw);
            $prepaidDate = self::loanFormParseDateYmd($prepaidDateRaw);
            if ($prepaidAmount === null || $prepaidDate === null) {
                return null;
            }
            if (extension_loaded('bcmath')) {
                if (bccomp($prepaidAmount, '0', 2) !== 1) {
                    return null;
                }
            } elseif ((float) $prepaidAmount <= 0.0) {
                return null;
            }
            $parsed = loan_principal_and_annual_for_prepaid_save($principalRaw, $rateRaw, $checksFields);
            if ($parsed === false) {
                return null;
            }
            $principalStr = $parsed['principalStr'];
            $rateStr = $parsed['rateStr'];
        } else {
            $parsed = loan_principal_and_annual_for_io_amortizing_save($principalRaw, $rateRaw, $checksFields);
            if ($parsed === false) {
                return null;
            }
            $principalStr = $parsed['principalStr'];
            $rateStr = $parsed['rateStr'];
        }

        return [
            'entityId' => $entityId,
            'name' => $name,
            'funding' => $funding,
            'origin' => $origin,
            'maturity' => $maturity,
            'paymentType' => $paymentType,
            'principalStr' => $principalStr,
            'rateStr' => $rateStr,
            'prepaidAmount' => $prepaidAmount,
            'prepaidDate' => $prepaidDate,
            'checksFields' => $checksFields,
        ];
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

        $payload = $this->parseLoanFormSavePayload();
        if ($payload === null) {
            header('Location: /loans/new?invalid=1');
            exit;
        }

        $entityId = $payload['entityId'];
        $name = $payload['name'];
        $funding = $payload['funding'];
        $origin = $payload['origin'];
        $maturity = $payload['maturity'];
        $paymentType = $payload['paymentType'];
        $principalStr = $payload['principalStr'];
        $rateStr = $payload['rateStr'];
        $prepaidAmount = $payload['prepaidAmount'];
        $prepaidDate = $payload['prepaidDate'];
        $checksFields = $payload['checksFields'];

        $chk = db()->prepare('SELECT id FROM entities WHERE id = ?');
        $chk->execute([$entityId]);
        if ($chk->fetch() === false) {
            header('Location: /loans/new?invalid=1');
            exit;
        }

        $createFunding = isset($_POST['create_funding_principal_out']);
        if ($createFunding && !schema_table_has_column('loans', 'funding_principal_out_posted')) {
            header('Location: /loans/new?invalid=1');
            exit;
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
                    header('Location: /loans/new?invalid=1');
                    exit;
                }
            } elseif ((float) $principalStr <= 0.0) {
                header('Location: /loans/new?invalid=1');
                exit;
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
        $cidx = loan_loans_column_name_index();
        $closedSel = isset($cidx['closed_date']) ? ', closed_date' : ', CAST(NULL AS DATE) AS closed_date';
        $loan = dbOne(
            'SELECT id, entity_id, name, funding_source, origin_date, maturity_date, payment_type, principal_amount, annual_interest_rate, '
            . loan_sql_select_checks_column_expressions('')
            . ', prepaid_interest_amount, prepaid_interest_date, ' . $fpSel . $closedSel . ' FROM loans WHERE id = ?',
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
        $hasClosedDateCol = isset($cidx['closed_date']);
        $closedDateVal = '';
        $loanIsClosed = false;
        if ($hasClosedDateCol) {
            $cdRaw = $loan['closed_date'] ?? null;
            if ($cdRaw !== null && (string) $cdRaw !== '') {
                $closedDateVal = (string) $cdRaw;
                $loanIsClosed = true;
            }
        }
        $canMarkLoanClosed = loan_eligible_to_mark_closed_from_ledger($id);
        $defaultCloseDate = (new DateTimeImmutable('today'))->format('Y-m-d');
        header('Content-Type: text/html; charset=utf-8');
        render('loans_edit', [
            'title' => $title,
            'entities' => $entities,
            'entitiesEmpty' => $entities === [],
            'showInvalid' => isset($_GET['invalid']),
            'lid' => $lid,
            'curEntityId' => $curEntityId,
            'nameVal' => $nameVal,
            'selJpm' => $selJpm,
            'selNtrs' => $selNtrs,
            'origin' => $origin,
            'maturity' => $maturity,
            'chkIo' => $chkIo,
            'chkAm' => $chkAm,
            'chkPre' => $chkPre,
            'principalVal' => $principalVal,
            'rateVal' => $rateVal,
            'chkIcFixed' => $chkIcFixed,
            'chkIcDecl' => $chkIcDecl,
            'mIntVal' => $mIntVal,
            'mppVal' => $mppVal,
            'pamtVal' => $pamtVal,
            'pdateVal' => $pdateVal,
            'hasFundingPostedCol' => $hasFundingPostedCol,
            'fundingPosted' => $fundingPosted,
            'hasClosedDateCol' => $hasClosedDateCol,
            'loanIsClosed' => $loanIsClosed,
            'closedDateVal' => $closedDateVal,
            'canMarkLoanClosed' => $canMarkLoanClosed,
            'defaultCloseDate' => $defaultCloseDate,
        ]);
    }

    public function update(): void
    {
        csrf_verify_or_die();

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

        $payload = $this->parseLoanFormSavePayload();
        if ($payload === null) {
            header('Location: /loans/edit?id=' . $loanId . '&invalid=1');
            exit;
        }

        $entityId = $payload['entityId'];
        $name = $payload['name'];
        $funding = $payload['funding'];
        $origin = $payload['origin'];
        $maturity = $payload['maturity'];
        $paymentType = $payload['paymentType'];
        $principalStr = $payload['principalStr'];
        $rateStr = $payload['rateStr'];
        $prepaidAmount = $payload['prepaidAmount'];
        $prepaidDate = $payload['prepaidDate'];
        $checksFields = $payload['checksFields'];

        $postFunding = isset($_POST['post_funding_principal_out']);
        if ($postFunding && !schema_table_has_column('loans', 'funding_principal_out_posted')) {
            header('Location: /loans/edit?id=' . $loanId . '&invalid=1');
            exit;
        }
        if ($postFunding && $fundingPostedFlag) {
            $postFunding = false;
        }
        if ($postFunding) {
            if (extension_loaded('bcmath')) {
                if (bccomp($principalStr, '0', 2) !== 1) {
                    header('Location: /loans/edit?id=' . $loanId . '&invalid=1');
                    exit;
                }
            } elseif ((float) $principalStr <= 0.0) {
                header('Location: /loans/edit?id=' . $loanId . '&invalid=1');
                exit;
            }
        }

        $chk = db()->prepare('SELECT id FROM entities WHERE id = ?');
        $chk->execute([$entityId]);
        if ($chk->fetch() === false) {
            header('Location: /loans/edit?id=' . $loanId . '&invalid=1');
            exit;
        }

        $exists = db()->prepare('SELECT id FROM loans WHERE id = ?');
        $exists->execute([$loanId]);
        if ($exists->fetch() === false) {
            header('Location: /loans');
            exit;
        }

        $idx = loan_loans_column_name_index();

        $finalClosed = null;
        if (isset($idx['closed_date'])) {
            $exRow = dbOne('SELECT closed_date AS cd FROM loans WHERE id = ?', [$loanId]);
            $existingClosedRaw = null;
            if ($exRow !== null && isset($exRow['cd']) && $exRow['cd'] !== null && (string) $exRow['cd'] !== '') {
                $existingClosedRaw = (string) $exRow['cd'];
            }
            if ($existingClosedRaw !== null) {
                $parsedExisting = self::loanFormParseDateYmd($existingClosedRaw);
                $finalClosed = $parsedExisting !== null ? $parsedExisting : $existingClosedRaw;
            } elseif (isset($_POST['mark_loan_closed'])) {
                $cRaw = trim((string) ($_POST['closed_date'] ?? ''));
                $finalClosed = self::loanFormParseDateYmd($cRaw);
                if ($finalClosed === null) {
                    header('Location: /loans/edit?id=' . $loanId . '&invalid=1');
                    exit;
                }
                if (!loan_eligible_to_mark_closed_from_ledger($loanId)) {
                    header('Location: /loans/edit?id=' . $loanId . '&invalid=1');
                    exit;
                }
            }
        }

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
        $updParams = array_merge($updParams, [$funding, $origin, $maturity, $paymentType, $prepaidAmount, $prepaidDate]);
        if (isset($idx['closed_date'])) {
            $setParts[] = 'closed_date = ?';
            $updParams[] = $finalClosed;
        }
        $updParams[] = $loanId;
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
