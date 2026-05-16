<?php

declare(strict_types=1);

require_once __DIR__ . '/../lib/payoff_helpers.php';

final class PayoffController
{
    public function form(): void
    {
        $pdo = db();
        $stmt = $pdo->prepare(
            'SELECT l.id, l.name AS loan_name, e.name AS entity_name '
            . 'FROM loans l INNER JOIN entities e ON e.id = l.entity_id '
            . 'ORDER BY e.name ASC, l.name ASC'
        );
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $loanOptions = [];
        foreach ($rows as $row) {
            $loanOptions[] = [
                'id' => (int) ($row['id'] ?? 0),
                'loanName' => (string) ($row['loan_name'] ?? ''),
                'entityName' => (string) ($row['entity_name'] ?? ''),
            ];
        }
        $today = (new DateTimeImmutable('today'))->format('Y-m-d');
        header('Content-Type: text/html; charset=utf-8');
        render('payoff_form', [
            'title' => 'Payoff statement',
            'loanOptions' => $loanOptions,
            'dateQuotedDefault' => $today,
            'payoffGoodThruDefault' => $today,
            'showInvalid' => isset($_GET['invalid']),
        ]);
    }

    public function statement(): void
    {
        csrf_verify_or_die();

        $loanId = (int) ($_POST['loan_id'] ?? 0);
        $dateQuotedRaw = trim((string) ($_POST['date_quoted'] ?? ''));
        $payoffGoodThruRaw = trim((string) ($_POST['payoff_good_thru'] ?? ''));

        $parseYmd = static function (string $s): ?string {
            $d = DateTimeImmutable::createFromFormat('Y-m-d', $s);

            return $d instanceof DateTimeImmutable && $d->format('Y-m-d') === $s ? $s : null;
        };

        $dateQuoted = $parseYmd($dateQuotedRaw);
        $payoffGoodThru = $parseYmd($payoffGoodThruRaw);

        if ($loanId < 1 || $dateQuoted === null || $payoffGoodThru === null) {
            header('Location: /payoff?invalid=1');
            exit;
        }

        if ($payoffGoodThru < $dateQuoted) {
            header('Location: /payoff?invalid=1');
            exit;
        }

        $idx = loan_loans_column_name_index();
        $monthlySel = isset($idx['monthly_interest'])
            ? 'l.monthly_interest'
            : 'CAST(NULL AS DECIMAL(12,2)) AS monthly_interest';
        $icmSel = isset($idx['interest_calc_method'])
            ? 'l.interest_calc_method'
            : "'fixed' AS interest_calc_method";
        $ppmSel = isset($idx['principal_payment_monthly'])
            ? 'l.principal_payment_monthly'
            : 'CAST(NULL AS DECIMAL(12,2)) AS principal_payment_monthly';
        $stmt = db()->prepare(
            'SELECT l.name AS loan_name, e.name AS entity_name, l.origin_date, l.principal_amount, l.annual_interest_rate, '
            . $monthlySel . ', ' . $icmSel . ', ' . $ppmSel
            . ' FROM loans l INNER JOIN entities e ON e.id = l.entity_id WHERE l.id = ?'
        );
        $stmt->execute([$loanId]);
        $loanRow = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($loanRow === false) {
            header('Location: /payoff?invalid=1');
            exit;
        }

        $originRaw = $loanRow['origin_date'] ?? null;
        if ($originRaw === null || (string) $originRaw === '') {
            header('Location: /payoff?invalid=1');
            exit;
        }
        $origin = DateTimeImmutable::createFromFormat('Y-m-d', (string) $originRaw);
        if (!$origin instanceof DateTimeImmutable || $origin->format('Y-m-d') !== (string) $originRaw) {
            header('Location: /payoff?invalid=1');
            exit;
        }

        $principalBalance = compute_principal_balance($loanRow, $dateQuoted);
        $annualRateStr = $loanRow['annual_interest_rate'] !== null && $loanRow['annual_interest_rate'] !== ''
            ? (string) $loanRow['annual_interest_rate']
            : '0.000';
        $monthlyInterestStored = $loanRow['monthly_interest'] ?? null;
        $monthlyInterestStoredStr = $monthlyInterestStored !== null && (string) $monthlyInterestStored !== ''
            ? (string) $monthlyInterestStored
            : null;

        $cycleStart = payoff_cycle_start_for_date($origin, $dateQuoted);
        $D = (int) $origin->format('d');
        $prevMonthAnchor = $cycleStart->modify('first day of this month')->modify('-1 month');
        $fullStart = payoff_date_with_clamped_dom(
            (int) $prevMonthAnchor->format('Y'),
            (int) $prevMonthAnchor->format('m'),
            $D
        );
        $fullEnd = $cycleStart->modify('-1 day');
        $perdiemStart = $cycleStart;
        $perdiemEnd = new DateTimeImmutable($payoffGoodThru);

        $monthlyForInterest = self::payoffMonthlyInterestAmount(
            $principalBalance,
            $annualRateStr,
            $monthlyInterestStoredStr
        );
        $fullInterestAmount = checks_normalize_money_2($monthlyForInterest);

        $daysInclusive = self::payoffInclusiveCalendarDays($perdiemStart, $perdiemEnd);
        $dailyRate = self::payoffDailyRateFromMonthly($monthlyForInterest);
        $perdiemInterestAmount = self::payoffMultiplyMoneyByIntDays($dailyRate, $daysInclusive);

        $totalDue = checks_add_money_2(checks_add_money_2($principalBalance, $fullInterestAmount), $perdiemInterestAmount);

        $loanLabel = (string) ($loanRow['entity_name'] ?? '') . ' — ' . (string) ($loanRow['loan_name'] ?? '');

        header('Content-Type: text/html; charset=utf-8');
        render('payoff_statement', [
            'title' => 'Loan payoff statement',
            'loanLabel' => $loanLabel,
            'loanId' => $loanId,
            'dateQuoted' => $dateQuoted,
            'payoffGoodThru' => $payoffGoodThru,
            'dateQuotedDisp' => self::payoffFormatDateLong($dateQuoted),
            'payoffGoodThruDisp' => self::payoffFormatDateLong($payoffGoodThru),
            'fullStartDisp' => self::payoffFormatDateLong($fullStart->format('Y-m-d')),
            'fullEndDisp' => self::payoffFormatDateLong($fullEnd->format('Y-m-d')),
            'perdiemStartDisp' => self::payoffFormatDateLong($perdiemStart->format('Y-m-d')),
            'perdiemEndDisp' => self::payoffFormatDateLong($perdiemEnd->format('Y-m-d')),
            'principalDisp' => checks_format_money_display_2($principalBalance),
            'fullInterestDisp' => checks_format_money_display_2($fullInterestAmount),
            'perdiemInterestDisp' => checks_format_money_display_2($perdiemInterestAmount),
            'totalDueDisp' => checks_format_money_display_2($totalDue),
            'dailyRateDisp' => checks_format_money_display_2(checks_normalize_money_2($dailyRate)),
            'daysInclusive' => $daysInclusive,
        ]);
    }

    /**
     * @return string normalized decimal string (2 dp)
     */
    private static function payoffMonthlyInterestAmount(
        string $principalBalance,
        string $annualRatePercent,
        ?string $monthlyInterestDb
    ): string {
        if ($monthlyInterestDb !== null && trim($monthlyInterestDb) !== '') {
            return checks_normalize_money_2($monthlyInterestDb);
        }

        return loan_simple_monthly_interest($principalBalance, $annualRatePercent);
    }

    private static function payoffInclusiveCalendarDays(DateTimeImmutable $start, DateTimeImmutable $end): int
    {
        if ($end < $start) {
            return 0;
        }

        return (int) $start->diff($end)->days + 1;
    }

    /**
     * daily_rate = monthly_interest / 30
     */
    private static function payoffDailyRateFromMonthly(string $monthlyNormalized): string
    {
        if (extension_loaded('bcmath')) {
            if (bccomp($monthlyNormalized, '0', 2) <= 0) {
                return '0.00';
            }

            return bcdiv($monthlyNormalized, '30', 8);
        }
        $m = (float) $monthlyNormalized;
        if ($m <= 0.0) {
            return '0.00';
        }

        return number_format($m / 30.0, 8, '.', '');
    }

    /**
     * @return string 2 dp normalized
     */
    private static function payoffMultiplyMoneyByIntDays(string $moneyPerDayHighPrecision, int $days): string
    {
        if ($days < 1) {
            return '0.00';
        }
        if (extension_loaded('bcmath')) {
            $prod = bcmul($moneyPerDayHighPrecision, (string) $days, 8);

            return number_format(round((float) $prod, 2, PHP_ROUND_HALF_UP), 2, '.', '');
        }

        return number_format(round((float) $moneyPerDayHighPrecision * $days, 2, PHP_ROUND_HALF_UP), 2, '.', '');
    }

    private static function payoffFormatDateLong(string $ymd): string
    {
        $d = DateTimeImmutable::createFromFormat('Y-m-d', $ymd);

        return $d instanceof DateTimeImmutable ? $d->format('M j, Y') : $ymd;
    }
}
