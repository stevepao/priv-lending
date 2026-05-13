<?php

declare(strict_types=1);

final class ReportController
{
    public function index(): void
    {
        $title = 'Report';
        $parseYmd = static function (string $s): ?string {
            $s = trim($s);
            if ($s === '') {
                return null;
            }
            $d = DateTimeImmutable::createFromFormat('Y-m-d', $s);

            return $d instanceof DateTimeImmutable && $d->format('Y-m-d') === $s ? $s : null;
        };

        $defaultStart = (new DateTimeImmutable('first day of this month'))->format('Y-m-d');
        $defaultEnd = (new DateTimeImmutable('last day of this month'))->format('Y-m-d');

        $startRaw = isset($_GET['start']) ? (string) $_GET['start'] : '';
        $endRaw = isset($_GET['end']) ? (string) $_GET['end'] : '';
        $start = $parseYmd($startRaw);
        $end = $parseYmd($endRaw);
        if ($start === null) {
            $start = $defaultStart;
        }
        if ($end === null) {
            $end = $defaultEnd;
        }

        $dateOrderError = $start > $end;

        $interestInDisp = '0.00';
        $locInterestOutDisp = '0.00';
        $principalPaidDisp = '0.00';
        $netIncomeDisp = '0.00';

        if (!$dateOrderError) {
            $pdo = db();
            $stmt = $pdo->prepare(
                'SELECT COALESCE(SUM(CASE WHEN category = ? THEN amount ELSE 0 END), 0) AS interest_in_sum, '
                . 'COALESCE(SUM(CASE WHEN category = ? THEN amount ELSE 0 END), 0) AS loc_interest_sum, '
                . 'COALESCE(SUM(CASE WHEN category IN (?, ?) THEN amount ELSE 0 END), 0) AS principal_net_sum '
                . 'FROM cash_events WHERE event_date >= ? AND event_date <= ?'
            );
            $stmt->execute(['interest', 'loc_interest', 'principal_in', 'principal_out', $start, $end]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            $interestInRaw = is_array($row) ? (string) ($row['interest_in_sum'] ?? '0') : '0';
            $locSumRaw = is_array($row) ? (string) ($row['loc_interest_sum'] ?? '0') : '0';
            $principalNetSumRaw = is_array($row) ? (string) ($row['principal_net_sum'] ?? '0') : '0';

            $interestInDisp = checks_normalize_money_2($interestInRaw);
            $locSumNorm = checks_normalize_money_2($locSumRaw);
            $principalPaidDisp = checks_normalize_money_2($principalNetSumRaw);

            if (extension_loaded('bcmath')) {
                $locInterestOutDisp = bcmul($locSumNorm, '-1', 2);
                $netIncomeDisp = bcsub($interestInDisp, $locInterestOutDisp, 2);
            } else {
                $locInterestOutDisp = number_format(-(float) $locSumNorm, 2, '.', '');
                $netIncomeDisp = number_format((float) $interestInDisp - (float) $locInterestOutDisp, 2, '.', '');
            }
        }

        header('Content-Type: text/html; charset=utf-8');
        render('report_index', [
            'title' => $title,
            'start' => $start,
            'end' => $end,
            'dateOrderError' => $dateOrderError,
            'interestInDisp' => $interestInDisp,
            'locInterestOutDisp' => $locInterestOutDisp,
            'principalPaidDisp' => $principalPaidDisp,
            'netIncomeDisp' => $netIncomeDisp,
        ]);
    }
}
