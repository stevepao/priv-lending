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
}
