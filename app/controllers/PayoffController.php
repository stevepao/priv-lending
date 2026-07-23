<?php

declare(strict_types=1);

require_once __DIR__ . '/../lib/payoff_helpers.php';

use Dompdf\Dompdf;
use Dompdf\Options;

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

        $dateQuoted = self::payoffParseYmd($dateQuotedRaw);
        $payoffGoodThru = self::payoffParseYmd($payoffGoodThruRaw);
        $lastMonthInterestPaid = isset($_POST['last_month_interest_paid'])
            && (string) $_POST['last_month_interest_paid'] !== ''
            && (string) $_POST['last_month_interest_paid'] !== '0';

        $viewData = self::buildPayoffStatementViewData($loanId, $dateQuoted, $payoffGoodThru, $lastMonthInterestPaid);
        if ($viewData === null) {
            header('Location: /payoff?invalid=1');
            exit;
        }

        header('Content-Type: text/html; charset=utf-8');
        render('payoff_statement', $viewData);
    }

    public function pdf(): void
    {
        if (!class_exists(Dompdf::class)) {
            http_response_code(500);
            header('Content-Type: text/plain; charset=utf-8');
            echo "PDF generation is not available (install Composer dependencies).\n";
            exit;
        }

        $loanId = (int) ($_GET['loan_id'] ?? 0);
        $dateQuoted = self::payoffParseYmd(trim((string) ($_GET['date_quoted'] ?? '')));
        $payoffGoodThru = self::payoffParseYmd(trim((string) ($_GET['payoff_good_thru'] ?? '')));
        $lastMonthRaw = strtolower(trim((string) ($_GET['last_month_interest_paid'] ?? '')));
        $lastMonthInterestPaid = in_array($lastMonthRaw, ['1', 'true', 'yes', 'on'], true);

        $viewData = self::buildPayoffStatementViewData($loanId, $dateQuoted, $payoffGoodThru, $lastMonthInterestPaid);
        if ($viewData === null) {
            header('Location: /payoff?invalid=1');
            exit;
        }

        ob_start();
        render('payoff_statement_pdf', $viewData);
        $html = ob_get_clean();

        $options = new Options();
        $options->setDefaultFont('DejaVu Sans');
        $options->setIsRemoteEnabled(false);

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('letter', 'portrait');
        $dompdf->render();

        $loanSafe = self::payoffSanitizeFilenameSegment((string) ($viewData['loanNameForFile'] ?? 'Loan'));
        $datePart = (string) ($viewData['dateQuotedYmd'] ?? '');
        $filename = 'Loan Payoff Statement - ' . $loanSafe . ' - ' . $datePart . '.pdf';

        $dompdf->stream($filename, ['Attachment' => true]);
    }

    private static function payoffParseYmd(string $s): ?string
    {
        if ($s === '') {
            return null;
        }
        $d = DateTimeImmutable::createFromFormat('Y-m-d', $s);

        return $d instanceof DateTimeImmutable && $d->format('Y-m-d') === $s ? $s : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function buildPayoffStatementViewData(
        int $loanId,
        ?string $dateQuoted,
        ?string $payoffGoodThru,
        bool $lastMonthInterestPaid = false
    ): ?array {
        if ($loanId < 1 || $dateQuoted === null || $payoffGoodThru === null) {
            return null;
        }
        if ($payoffGoodThru < $dateQuoted) {
            return null;
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
            'SELECT l.name AS loan_name, l.notes AS loan_notes, e.name AS entity_name, '
            . 'b.name AS borrower_name, b.address AS borrower_address, b.city AS borrower_city, b.state AS borrower_state, b.zip AS borrower_zip, '
            . 'l.origin_date, l.principal_amount, l.annual_interest_rate, '
            . $monthlySel . ', ' . $icmSel . ', ' . $ppmSel
            . ' FROM loans l '
            . 'INNER JOIN entities e ON e.id = l.entity_id '
            . 'INNER JOIN borrowers b ON b.id = e.borrower_id '
            . 'WHERE l.id = ?'
        );
        $stmt->execute([$loanId]);
        $loanRow = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($loanRow === false) {
            return null;
        }

        $originRaw = $loanRow['origin_date'] ?? null;
        if ($originRaw === null || (string) $originRaw === '') {
            return null;
        }
        $origin = DateTimeImmutable::createFromFormat('Y-m-d', (string) $originRaw);
        if (!$origin instanceof DateTimeImmutable || $origin->format('Y-m-d') !== (string) $originRaw) {
            return null;
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
        $fullInterestAmount = $lastMonthInterestPaid
            ? '0.00'
            : checks_normalize_money_2($monthlyForInterest);

        $daysInclusive = self::payoffInclusiveCalendarDays($perdiemStart, $perdiemEnd);
        $dailyRate = self::payoffDailyRateFromMonthly($monthlyForInterest);
        $perdiemInterestAmount = self::payoffMultiplyMoneyByIntDays($dailyRate, $daysInclusive);

        $totalDue = checks_add_money_2(checks_add_money_2($principalBalance, $fullInterestAmount), $perdiemInterestAmount);

        $loanName = trim((string) ($loanRow['loan_name'] ?? ''));
        $loanNotes = $loanRow['loan_notes'] !== null && (string) $loanRow['loan_notes'] !== '' ? trim((string) $loanRow['loan_notes']) : '';
        $propertyLine = $loanName !== '' ? $loanName : $loanNotes;

        $entityName = trim((string) ($loanRow['entity_name'] ?? ''));
        $borrowerName = trim((string) ($loanRow['borrower_name'] ?? ''));
        $borrowerAddress = $loanRow['borrower_address'] !== null && (string) $loanRow['borrower_address'] !== '' ? trim((string) $loanRow['borrower_address']) : '';
        $borrowerCityStateZip = self::payoffBorrowerCityStateZipLine(
            $loanRow['borrower_city'] ?? null,
            $loanRow['borrower_state'] ?? null,
            $loanRow['borrower_zip'] ?? null
        );

        $fullStartMd = self::payoffFormatDateMdY($fullStart->format('Y-m-d'));
        $fullEndMd = self::payoffFormatDateMdY($fullEnd->format('Y-m-d'));
        $perdiemStartMd = self::payoffFormatDateMdY($perdiemStart->format('Y-m-d'));
        $perdiemEndMd = self::payoffFormatDateMdY($perdiemEnd->format('Y-m-d'));

        return [
            'title' => 'Loan payoff statement',
            'loanId' => $loanId,
            'dateQuotedYmd' => $dateQuoted,
            'payoffGoodThruYmd' => $payoffGoodThru,
            'loanNameForFile' => $loanName !== '' ? $loanName : 'Loan',
            'entityName' => $entityName,
            'borrowerName' => $borrowerName,
            'borrowerAddress' => $borrowerAddress,
            'borrowerCityStateZip' => $borrowerCityStateZip,
            'propertyLine' => $propertyLine,
            'dateQuotedDisp' => self::payoffFormatDateMdY($dateQuoted),
            'payoffGoodThruDisp' => self::payoffFormatDateMdY($payoffGoodThru),
            'interestFullRange' => $fullStartMd . ' - ' . $fullEndMd,
            'interestPerdiemRange' => $perdiemStartMd . ' - ' . $perdiemEndMd,
            'showFullMonthInterest' => !$lastMonthInterestPaid,
            'lastMonthInterestPaid' => $lastMonthInterestPaid,
            'principalDisp' => self::payoffFormatMoneyUsd($principalBalance),
            'fullInterestDisp' => self::payoffFormatMoneyUsd($fullInterestAmount),
            'perdiemInterestDisp' => self::payoffFormatMoneyUsd($perdiemInterestAmount),
            'totalDueDisp' => self::payoffFormatMoneyUsd($totalDue),
            'dailyRateDisp' => self::payoffFormatMoneyUsd(checks_normalize_money_2($dailyRate)),
        ];
    }

    private static function payoffSanitizeFilenameSegment(string $name): string
    {
        $s = preg_replace('/[^A-Za-z0-9 _.-]+/u', '', $name);
        $s = trim((string) $s);

        return $s !== '' ? $s : 'Loan';
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

    /** US-style M/D/YYYY for payoff statement (no leading zeros on month/day). */
    private static function payoffFormatDateMdY(string $ymd): string
    {
        $d = DateTimeImmutable::createFromFormat('Y-m-d', $ymd);

        return $d instanceof DateTimeImmutable ? $d->format('n/j/Y') : $ymd;
    }

    /** Money as $#,##0.00 */
    private static function payoffFormatMoneyUsd(string $normalizedTwoDp): string
    {
        $n = checks_normalize_money_2($normalizedTwoDp);

        return '$' . number_format((float) $n, 2, '.', ',');
    }

    /**
     * @param mixed $city
     * @param mixed $state
     * @param mixed $zip
     */
    private static function payoffBorrowerCityStateZipLine($city, $state, $zip): string
    {
        $c = trim((string) ($city ?? ''));
        $s = trim((string) ($state ?? ''));
        $z = trim((string) ($zip ?? ''));
        $stZip = trim($s . ' ' . $z);
        if ($c === '' && $stZip === '') {
            return '';
        }
        if ($c === '') {
            return $stZip;
        }
        if ($stZip === '') {
            return $c;
        }

        return $c . ', ' . $stZip;
    }
}
